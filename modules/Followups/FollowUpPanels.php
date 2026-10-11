<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Followups\Controllers\FollowUpController;
use Modules\Followups\Services\FollowUpService;

/**
 * "Follow-ups" boxes on contract and project pages, contributed through
 * RelatedPanelRegistry — Contracts and Projects don't know this module
 * exists, and this module never reads their tables (the record comes in
 * as the host page's subject; the create button is checked through
 * RecordTypeRegistry::find()).
 *
 * RelatedPanelRegistry shows a panel only when Follow-ups is available
 * to the user and they hold followups.view. Each row is then checked
 * with AuthorizationEngine exactly like the follow-up page itself
 * (FollowUpService::find()), so the panel never lists a follow-up the
 * user couldn't open.
 */
final class FollowUpPanels
{
    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly AuthorizationEngine $authorization,
        private readonly RecordTypeRegistry $recordTypes,
        private readonly TemplateEngine $templates,
    ) {
    }

    public function registerAll(RelatedPanelRegistry $panels): void
    {
        foreach (FollowUpController::REGISTRY_RELATED_TYPES as $subjectType) {
            $panels->register(new RelatedPanel(
                subjectType: $subjectType,
                id: 'followups.' . $subjectType,
                module: 'followups',
                titleKey: 'followups.title',
                render: fn (array $subject, array $user): string => $this->render($subjectType, $subject, $user),
                order: 60,
                permission: Permission::FOLLOWUPS_VIEW,
                icon: 'calendar-check',
            ));
        }
    }

    public function render(string $subjectType, array $subject, array $user): string
    {
        $subjectId = (int) ($subject['id'] ?? 0);
        if ($subjectId <= 0) {
            return '';
        }

        $rows = array_values(array_filter(
            $this->followUps->forRelated($subjectType, $subjectId),
            fn (array $row) => $this->authorization->can($user, Permission::FOLLOWUPS_VIEW, $row)
        ));

        // Same checks the create route + form apply: followups.create, and
        // the record itself viewable by this user (scope included).
        $canCreate = $this->authorization->can($user, Permission::FOLLOWUPS_CREATE)
            && $this->recordTypes->find($subjectType, $subjectId, $user) !== null;

        return $this->templates->render('followups/_panel', [
            'followUps' => $rows,
            'canCreate' => $canCreate,
            'createUrl' => '/followups/create?' . http_build_query(['related_type' => $subjectType, 'related_id' => $subjectId]),
        ]);
    }
}
