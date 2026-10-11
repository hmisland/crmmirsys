<?php

declare(strict_types=1);

namespace App\Infrastructure\Records;

use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleGate;

/**
 * Slot for panels modules add to other records' pages. Host pages
 * render whatever is registered for their subject type through
 * partials/related-panels.php; they never name the contributing module.
 *
 * A panel is shown only when its module is available for the user and
 * the user holds the panel's permission (ModuleGate). A panel whose
 * render callback throws is logged and skipped — a broken add-on must
 * not break the host page (same rule as EventBus listeners).
 */
final class RelatedPanelRegistry
{
    /** @var array<string, array<string, RelatedPanel>> subjectType => id => panel */
    private array $panels = [];

    public function __construct(
        private readonly ModuleGate $gate,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** First registration of a subject+id wins; a duplicate is ignored (and logged) and returns false. */
    public function register(RelatedPanel $panel): bool
    {
        if (isset($this->panels[$panel->subjectType][$panel->id])) {
            $this->logger?->warning('Related panel already registered', ['subject' => $panel->subjectType, 'panel' => $panel->id]);

            return false;
        }

        $this->panels[$panel->subjectType][$panel->id] = $panel;

        return true;
    }

    /** @return RelatedPanel[] panels this user may see on the subject type's page, ordered */
    public function panelsFor(string $subjectType, ?array $user): array
    {
        $visible = array_filter(
            $this->panels[$subjectType] ?? [],
            fn (RelatedPanel $p) => $this->gate->allows($user, $p->module, $p->permission)
        );

        usort($visible, fn (RelatedPanel $a, RelatedPanel $b) => [$a->order, $a->id] <=> [$b->order, $b->id]);

        return $visible;
    }

    /**
     * Rendered panels for one record page; panels returning empty HTML
     * are left out.
     *
     * @return array<int, array{panel: RelatedPanel, html: string}>
     */
    public function render(string $subjectType, array $subject, ?array $user): array
    {
        if ($user === null) {
            return [];
        }

        $out = [];
        foreach ($this->panelsFor($subjectType, $user) as $panel) {
            try {
                $html = (string) ($panel->render)($subject, $user);
            } catch (\Throwable $e) {
                $this->logger?->error('Related panel failed', ['subject' => $subjectType, 'panel' => $panel->id, 'exception' => $e->getMessage()]);
                continue;
            }

            if (trim($html) !== '') {
                $out[] = ['panel' => $panel, 'html' => $html];
            }
        }

        return $out;
    }
}
