<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Projects\Services\ProjectService;

/**
 * "Projects" boxes on contact, company, deal and contract pages,
 * contributed through RelatedPanelRegistry — the host pages (and Sales /
 * Contracts) don't know this module exists. RelatedPanelRegistry shows a
 * panel only when Projects is available to the user and they hold
 * projects.view, and renders a host page's slot only when that page
 * exists (the deal/contract ones only when their module is installed).
 * Rows go through ProjectService, so scope + membership apply.
 */
final class ProjectPanels
{
    public const LIMIT = 5;

    /** Core subject type => project column that points at it. */
    private const PARTIES = ['contact' => 'contact_id', 'company' => 'company_id'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly TemplateEngine $templates,
    ) {
    }

    public function registerAll(RelatedPanelRegistry $panels): void
    {
        foreach ([...array_keys(self::PARTIES), ...ProjectService::LINKABLE] as $subjectType) {
            $panels->register(new RelatedPanel(
                subjectType: $subjectType,
                id: 'projects.' . $subjectType,
                module: ProjectsModule::KEY,
                titleKey: 'projects.title',
                render: fn (array $subject, array $user): string => $this->render($subjectType, $subject, $user),
                order: 55,
                permission: ProjectPermission::VIEW,
                icon: 'kanban',
            ));
        }
    }

    public function render(string $subjectType, array $subject, array $user): string
    {
        $subjectId = (int) ($subject['id'] ?? 0);
        if ($subjectId <= 0) {
            return '';
        }

        $translator = Locale::get();
        $fmt = new LocaleFormatter($translator !== null ? $translator->locale() : 'en');
        $canCreate = $this->projects->userCan($user, ProjectPermission::CREATE);

        // Deal / contract page: projects linked to it, "New project" prefilled
        // from it, and "Link existing project".
        if (in_array($subjectType, ProjectService::LINKABLE, true)) {
            $projects = $this->projects->linkedTo($user, $subjectType, $subjectId, self::LIMIT);
            // Contract page (Stage 3-A): "New project" / "Link existing" only while the
            // user may link to this contract; "Unlink" per project they may unlink.
            $canLink = $this->projects->canLinkRecord($user, $subjectType, $subjectId);

            return $this->templates->render('projects/_panel', [
                'projects' => $projects,
                'total' => $this->projects->countLinkedTo($user, $subjectType, $subjectId),
                'canCreate' => $canCreate && $canLink,
                'unlinks' => $subjectType === 'contract' ? $this->projects->contractUnlinks($user, $subjectId, $projects) : [],
                'returnTo' => $subjectType === 'contract' ? (string) $this->projects->recordUrl($subjectType, $subjectId) : '',
                'createUrl' => '/projects/create?' . http_build_query(['from_' . $subjectType => $subjectId]),
                'listUrl' => '',
                'emptyKey' => 'projects.panel_empty_' . $subjectType,
                'linkType' => $subjectType,
                'linkId' => $subjectId,
                'linkable' => $this->projects->linkableProjects($user, $subjectType, $subjectId),
                'fmt' => $fmt,
            ]);
        }

        $column = self::PARTIES[$subjectType] ?? null;
        if ($column === null) {
            return '';
        }

        $filters = [$column => $subjectId];

        return $this->templates->render('projects/_panel', [
            'projects' => $this->projects->list($user, $filters, self::LIMIT, 0),
            'total' => $this->projects->count($user, $filters),
            'canCreate' => $canCreate,
            'createUrl' => '/projects/create?' . http_build_query([$column => $subjectId]),
            'listUrl' => '/projects?' . http_build_query([$subjectType => $subjectId]),
            'emptyKey' => 'projects.panel_empty',
            'linkType' => null,
            'linkId' => 0,
            'linkable' => [],
            'fmt' => $fmt,
        ]);
    }
}
