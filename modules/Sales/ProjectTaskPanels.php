<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Controllers\TaskController;
use Modules\Sales\Services\TaskService;

/**
 * The Task Manager's boxes on a project page, contributed through
 * RelatedPanelRegistry — Projects never learns Sales exists (it only
 * names its own subject types, "project" and "project_overview"), and
 * this class names Projects only by those subject-type strings, the
 * same way TaskRelatedRecords names the "project" record type.
 *
 * Both panels show only when Sales is available to the user and they
 * hold tasks.view (RelatedPanelRegistry / ModuleGate); the rows are
 * TaskService::visibleForSubject() — the same visibility rule as every
 * task list (task scope + access to the project). No task logic here.
 */
final class ProjectTaskPanels
{
    public const SUBJECT = 'project';
    /** The project page's overview card slot (stats only, no list). */
    public const OVERVIEW_SUBJECT = 'project_overview';

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TemplateEngine $templates,
    ) {
    }

    public function registerAll(RelatedPanelRegistry $panels): void
    {
        $panels->register(new RelatedPanel(
            subjectType: self::SUBJECT,
            id: 'tasks.project',
            module: 'sales',
            titleKey: 'nav.tasks',
            render: fn (array $subject, array $user): string => $this->render($subject, $user),
            order: 40,
            permission: Permission::TASKS_VIEW,
            icon: 'check-square',
        ));
        $panels->register(new RelatedPanel(
            subjectType: self::OVERVIEW_SUBJECT,
            id: 'tasks.project_overview',
            module: 'sales',
            titleKey: 'tasks.project_panel.progress',
            render: fn (array $subject, array $user): string => $this->renderOverview($subject, $user),
            order: 40,
            permission: Permission::TASKS_VIEW,
            icon: 'check-square',
        ));
    }

    public function render(array $project, array $user): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            return '';
        }

        $tasks = $this->tasks->visibleForSubject($user, self::SUBJECT, $projectId);

        return $this->templates->render('sales/_project_tasks_panel', [
            'projectId' => $projectId,
            'tasks' => $tasks,
            'summary' => $this->tasks->summaryFor($tasks),
            'assignees' => $this->tasks->assigneeNamesFor($tasks),
            'taskStatuses' => $this->tasks->statusMap(),
            'canCreate' => $this->tasks->capabilities($user)['create'],
            'users' => $this->tasks->assignableUsers((int) $user['organization_id']),
            'fmt' => TaskController::formatter(),
        ]);
    }

    public function renderOverview(array $project, array $user): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            return '';
        }

        return $this->templates->render('sales/_project_tasks_summary', [
            'summary' => $this->tasks->summaryFor($this->tasks->visibleForSubject($user, self::SUBJECT, $projectId)),
            'fmt' => TaskController::formatter(),
        ]);
    }
}
