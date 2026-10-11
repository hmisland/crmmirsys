<?php

declare(strict_types=1);

namespace Modules\Projects\Listeners;

use App\Application\Services\TimelineService;
use App\Core\Events\EventInterface;
use App\Core\Events\ListenerInterface;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use Modules\Projects\ProjectLinkRepository;
use Modules\Projects\ProjectsModule;
use Modules\Projects\Services\ProjectService;

/**
 * Deal → Project through EventBus. Subscribed in ProjectsModule by the
 * event's class NAME (a string) — this module never imports Sales; if
 * Sales isn't installed, nothing ever fires.
 *
 * The event is read by its public properties (organizationId, dealId,
 * stageName, isWon, isLost, actorUserId). If that shape ever changes,
 * the listener logs and does nothing (tests/lite cover the contract).
 *
 * - every project linked to the deal gets a timeline entry;
 * - on "won", the organization's optional auto-create (off by default)
 *   makes one draft project linked to the deal.
 *
 * Runs with no acting-user permission check (system reaction, like
 * Contracts' listener), scoped explicitly to the event's organization,
 * and only when Projects is active and entitled for that organization.
 */
final class DealStageChangedListener implements ListenerInterface
{
    private const REQUIRED = ['organizationId', 'dealId', 'stageName', 'isWon', 'isLost', 'actorUserId'];

    public function __construct(
        private readonly ProjectLinkRepository $links,
        private readonly TimelineService $timeline,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ProjectService $projects = null,
    ) {
    }

    public function handle(EventInterface $event): void
    {
        foreach (self::REQUIRED as $property) {
            if (!property_exists($event, $property)) {
                $this->logger?->warning('Projects: deal event has an unexpected shape; ignored', ['event' => $event::class, 'missing' => $property]);

                return;
            }
        }

        $organizationId = (int) $event->organizationId;
        if (!$this->projectsEnabledFor($organizationId)) {
            return;
        }

        $text = match (true) {
            (bool) $event->isWon => 'Linked deal won',
            (bool) $event->isLost => 'Linked deal lost',
            default => 'Linked deal moved to stage "' . (string) $event->stageName . '"',
        };
        $actor = $event->actorUserId !== null ? (int) $event->actorUserId : null;
        $dealId = (int) $event->dealId;
        $won = (bool) $event->isWon;

        OrganizationContext::runAs($organizationId, function () use ($dealId, $text, $actor, $won): void {
            foreach ($this->links->projectIdsLinkedTo('deal', $dealId) as $projectId) {
                $this->timeline->record('project', $projectId, 'deal_stage_changed', $text, $actor);
            }

            // The organization's optional "auto-create on won" (the service
            // checks the setting and "one project per deal").
            if ($won && $this->projects !== null) {
                $this->projects->autoCreateFromWonDeal($dealId, $actor);
            }
        });
    }

    /** Organization-level check (there is no acting user to resolve per-user overrides for). */
    private function projectsEnabledFor(int $organizationId): bool
    {
        $module = (new ModuleRepository())->findByKey(ProjectsModule::KEY);

        return $module !== null
            && $module['status'] === 'active'
            && (new OrganizationModuleRepository())->isEnabledForOrganization($organizationId, (int) $module['id']);
    }
}
