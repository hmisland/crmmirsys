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
    /** One row of the Projects list (the Progress cell); the row carries 'list_ids' = every id on the page. */
    public const LIST_ROW_SUBJECT = 'project_list_row';

    /** The Projects dashboard: task KPI tiles, and the "projects with overdue tasks" section. Subject: ['open_projects' => id => label]. */
    public const DASHBOARD_KPI_SUBJECT = 'project_dashboard_kpi';
    public const DASHBOARD_SUBJECT = 'project_dashboard';
    public const DASHBOARD_LIST_LIMIT = 10;

    /** @var array<string, array<int, int>> page ids key => project id => percent (one grouped query per page) */
    private array $progressByPage = [];

    /** @var array<string, array<int, array{open: int, overdue: int}>> dashboard key => per-project open/overdue (one grouped query per page) */
    private array $deadlinesByPage = [];

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
        $panels->register(new RelatedPanel(
            subjectType: self::LIST_ROW_SUBJECT,
            id: 'tasks.project_list_row',
            module: 'sales',
            titleKey: 'tasks.project_list.progress',
            render: fn (array $subject, array $user): string => $this->renderListRow($subject, $user),
            order: 40,
            permission: Permission::TASKS_VIEW,
        ));
        $panels->register(new RelatedPanel(
            subjectType: self::DASHBOARD_KPI_SUBJECT,
            id: 'tasks.project_dashboard_kpi',
            module: 'sales',
            titleKey: 'tasks.project_dashboard.open',
            render: fn (array $subject, array $user): string => $this->renderDashboardKpis($subject, $user),
            order: 40,
            permission: Permission::TASKS_VIEW,
        ));
        $panels->register(new RelatedPanel(
            subjectType: self::DASHBOARD_SUBJECT,
            id: 'tasks.project_dashboard',
            module: 'sales',
            titleKey: 'tasks.project_dashboard.overdue_title',
            render: fn (array $subject, array $user): string => $this->renderDashboardSection($subject, $user),
            order: 40,
            permission: Permission::TASKS_VIEW,
            icon: 'alert-triangle',
        ));
    }

    /** Open / overdue task tiles for the Projects dashboard (the projects' visible tasks). */
    public function renderDashboardKpis(array $subject, array $user): string
    {
        $labels = $this->openProjects($subject);
        if ($labels === []) {
            return '';
        }
        $deadlines = $this->deadlines($labels, $user);

        return $this->templates->render('sales/_project_dashboard_kpis', [
            'open' => array_sum(array_column($deadlines, 'open')),
            'overdue' => array_sum(array_column($deadlines, 'overdue')),
            'fmt' => TaskController::formatter(),
        ]);
    }

    /** The open projects with the most overdue tasks (each linked to its task board); nothing when none is late. */
    public function renderDashboardSection(array $subject, array $user): string
    {
        $labels = $this->openProjects($subject);
        $late = array_filter($this->deadlines($labels, $user), fn (array $d) => $d['overdue'] > 0);
        if ($late === []) {
            return '';
        }
        uasort($late, fn (array $a, array $b) => [$b['overdue'], $b['open']] <=> [$a['overdue'], $a['open']]);
        $rows = [];
        foreach (array_slice($late, 0, self::DASHBOARD_LIST_LIMIT, true) as $projectId => $d) {
            $rows[] = ['id' => $projectId, 'label' => $labels[$projectId] ?? ('#' . $projectId)] + $d;
        }

        return $this->templates->render('sales/_project_dashboard_overdue', [
            'rows' => $rows,
            'total' => count($late),
            'fmt' => TaskController::formatter(),
        ]);
    }

    /** @return array<int, string> */
    private function openProjects(array $subject): array
    {
        $labels = [];
        foreach (is_array($subject['open_projects'] ?? null) ? $subject['open_projects'] : [] as $id => $label) {
            if ((int) $id > 0) {
                $labels[(int) $id] = (string) $label;
            }
        }

        return $labels;
    }

    /** @param array<int, string> $labels */
    private function deadlines(array $labels, array $user): array
    {
        if ($labels === []) {
            return [];
        }
        $key = (int) ($user['id'] ?? 0) . ':' . md5(implode(',', array_keys($labels)));

        return $this->deadlinesByPage[$key] ??= $this->tasks->deadlinesForProjects($user, array_keys($labels));
    }

    /**
     * The Progress cell of one Projects-list row. The first row of a page
     * computes every row's percent in one grouped query
     * (TaskService::progressForProjects()); the others reuse it.
     */
    public function renderListRow(array $project, array $user): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            return '';
        }
        $ids = array_map('intval', is_array($project['list_ids'] ?? null) ? $project['list_ids'] : [$projectId]);
        $key = (int) ($user['id'] ?? 0) . ':' . implode(',', $ids);
        $this->progressByPage[$key] ??= $this->tasks->progressForProjects($user, $ids);
        $percent = $this->progressByPage[$key][$projectId] ?? 0;

        return $this->templates->render('sales/_project_progress_cell', [
            'percent' => $percent,
            'fmt' => TaskController::formatter(),
        ]);
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
