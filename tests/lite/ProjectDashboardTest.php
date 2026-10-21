<?php

declare(strict_types=1);

/**
 * Project Management Phase 3 — the Projects dashboard and deadline / delay
 * monitoring (ProjectDashboardService) plus the Task Manager's dashboard
 * figures (Sales, through RelatedPanelRegistry). Real router + middleware.
 * crm_local only, one transaction, rolled back. Figures are compared as
 * deltas, so rows already in crm_local don't matter.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use Modules\Projects\Services\ProjectDashboardService;
use Modules\Sales\Services\TaskService;
use Tests\Lite\Support\LocalApp;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter();
$boot = function () use ($projectsBoot): LocalApp {
    $a = $projectsBoot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'sales'")->fetchColumn() === 0) {
        ProjectsLite::activate($a, 'sales', 'Sales');
    }
    $a->setOrgEntitled('sales', true);
    $a->as($a->admin);
    $a->locale('en');

    return $a;
};
const P3_TODAY = '2026-06-15';
$dash = fn (LocalApp $a, ?array $user = null): array => (new ProjectDashboardService(ProjectsLite::service($a), new \Modules\Projects\ProjectRepository()))->overview($user ?? $a->admin, P3_TODAY);
/** A project row inserted directly (status/dates under test), owned by $owner (default admin). */
$project = function (LocalApp $a, string $name, string $status, ?string $end, ?int $owner = null, ?int $org = null): int {
    $owner ??= (int) $a->admin['id'];
    $a->pdo->prepare('INSERT INTO projects (organization_id, code, name, status, owner_id, expected_end_date, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$org ?? $a->orgId, 'P3-' . bin2hex(random_bytes(4)), $name, $status, $owner, $end, $owner]);

    return (int) $a->pdo->lastInsertId();
};
$milestone = function (LocalApp $a, int $projectId, string $date, string $status = 'planned', ?int $org = null): void {
    $a->pdo->prepare("INSERT INTO project_milestones (organization_id, project_id, name, date, status) VALUES (?, ?, 'P3 milestone', ?, ?)")->execute([$org ?? $a->orgId, $projectId, $date, $status]);
};
$attentionNames = fn (array $d): array => array_values(array_filter(array_column($d['attention']['rows'], 'name'), fn ($n) => str_starts_with((string) $n, 'P3 ')));
$delta = fn (array $after, array $before): array => [
    'active' => $after['counts']['active'] - $before['counts']['active'],
    'overdue' => $after['counts']['overdue'] - $before['counts']['overdue'],
    'ending_soon' => $after['counts']['ending_soon'] - $before['counts']['ending_soon'],
    'm_overdue' => $after['milestones']['overdue'] - $before['milestones']['overdue'],
    'm_upcoming' => $after['milestones']['upcoming'] - $before['milestones']['upcoming'],
];
$withMemberScope = function (LocalApp $a, string $key, ?string $scope, callable $fn): void {
    $old = $a->pdo->query("SELECT scope FROM role_permissions WHERE role_id = {$a->memberRoleId} AND permission_key = " . $a->pdo->quote($key))->fetchColumn();
    $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
    if ($scope !== null) {
        $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $scope]);
    }
    try {
        $fn();
    } finally {
        $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
        if ($old !== false) {
            $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $old]);
        }
        $a->as($a->admin);
    }
};
$questions = fn (LocalApp $a): int => (int) $a->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];

return [
    'deadlines: overdue / ending soon / late and upcoming milestones; completed and cancelled are never flagged' => function () use ($boot, $dash, $project, $milestone, $attentionNames, $delta) {
        $a = $boot();
        $before = $dash($a);
        $late = $project($a, 'P3 late active', 'active', '2026-06-10');
        $project($a, 'P3 done late', 'completed', '2026-06-01');
        $project($a, 'P3 cancelled late', 'cancelled', '2026-06-01');
        $project($a, 'P3 soon', 'planned', '2026-06-20');
        $project($a, 'P3 today', 'on_hold', P3_TODAY);
        $project($a, 'P3 far', 'active', '2026-08-30');
        $noEnd = $project($a, 'P3 no end, late milestone', 'draft', null);
        $milestone($a, $noEnd, '2026-06-01');                     // late (planned, past)
        $milestone($a, $noEnd, '2026-06-18');                     // upcoming
        $milestone($a, $late, '2026-06-02', 'completed');         // done: never late
        $milestone($a, $late, '2026-06-03', 'cancelled');         // cancelled: never late
        $closed = $project($a, 'P3 closed with old milestone', 'completed', '2026-05-01');
        $milestone($a, $closed, '2026-05-01');                    // closed project: never late
        $milestone($a, $closed, '2026-06-16');                    // closed project: not upcoming either

        $after = $dash($a);
        LiteAssert::same(['active' => 2, 'overdue' => 1, 'ending_soon' => 2, 'm_overdue' => 1, 'm_upcoming' => 1], $delta($after, $before));
        $names = $attentionNames($after);
        LiteAssert::same(['P3 late active', 'P3 no end, late milestone', 'P3 today', 'P3 soon'], $names, 'most urgent first, closed and far ones absent');
        $byName = array_column($after['attention']['rows'], null, 'name');
        LiteAssert::same([1, 0, 0], [(int) $byName['P3 late active']['is_overdue'], (int) $byName['P3 late active']['ends_soon'], (int) $byName['P3 late active']['overdue_milestones']], 'no contradictory reasons');
        LiteAssert::same([0, 1], [(int) $byName['P3 soon']['is_overdue'], (int) $byName['P3 soon']['ends_soon']]);
        LiteAssert::same(1, (int) $byName['P3 no end, late milestone']['overdue_milestones']);
        LiteAssert::true(in_array('P3 milestone', array_column($after['upcoming'], 'name'), true));
        LiteAssert::same(5, ProjectDashboardService::daysBetween('2026-06-10', P3_TODAY));
    },

    'page: KPI tiles link to existing views, attention reasons, upcoming milestones, entry button on the list' => function () use ($boot, $project, $milestone) {
        $a = $boot();
        $late = $project($a, 'P3 page late', 'active', '2020-01-01');
        $milestone($a, $late, '2020-01-02');
        $r = $a->request('GET', '/projects/dashboard');
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('Projects dashboard', $r['body']);
        LiteAssert::contains('href="/projects?status=active"', $r['body']);
        LiteAssert::contains('id="project-attention"', $r['body']);
        LiteAssert::contains('P3 page late', $r['body']);
        LiteAssert::contains('Overdue by', $r['body']);
        LiteAssert::contains('late milestone', $r['body']);
        LiteAssert::contains('href="/projects/dashboard"', $a->request('GET', '/projects')['body'], 'entry point on the list');
    },

    'visibility: own-scope users see only their projects; another organization never counts' => function () use ($boot, $dash, $project, $milestone, $attentionNames, $withMemberScope, $delta) {
        $a = $boot();
        $mine = $project($a, 'P3 member-owned late', 'active', '2026-06-01', (int) $a->member['id']);
        $project($a, 'P3 admin-owned late', 'active', '2026-06-01');
        $milestone($a, $mine, '2026-06-05');
        $foreign = ProjectsLite::foreignProject($a);
        if ($foreign !== null) {
            $a->pdo->exec("UPDATE projects SET expected_end_date = '2026-06-01' WHERE id = {$foreign}");
            $milestone($a, $foreign, '2026-06-02', 'planned', $a->otherOrgId);
        }
        $adminNames = $attentionNames($dash($a));
        LiteAssert::true(in_array('P3 admin-owned late', $adminNames, true) && in_array('P3 member-owned late', $adminNames, true));
        LiteAssert::false(in_array('XORG project', array_column($dash($a)['attention']['rows'], 'name'), true), 'foreign project absent');

        $withMemberScope($a, 'projects.view', 'own', function () use ($a, $dash, $attentionNames) {
            $a->as($a->member);
            $d = $dash($a, $a->member);
            LiteAssert::same(['P3 member-owned late'], $attentionNames($d));
            LiteAssert::false(in_array('P3 admin-owned late', array_column($d['attention']['rows'], 'name'), true));
            LiteAssert::same(1, $d['milestones']['overdue'] >= 1 ? 1 : 0);
            LiteAssert::notContains('P3 admin-owned late', $a->request('GET', '/projects/dashboard')['body']);
        });
    },

    'empty state: nothing visible → no tiles and no misleading zeros; no projects.view → 403; module off → 404' => function () use ($boot, $withMemberScope) {
        $a = $boot();
        $withMemberScope($a, 'projects.view', 'own', function () use ($a) {
            // The member owns nothing and belongs to nothing here.
            $a->pdo->prepare('DELETE FROM project_members WHERE user_id = ?')->execute([(int) $a->member['id']]);
            $a->pdo->prepare('UPDATE projects SET owner_id = ? WHERE owner_id = ? AND organization_id = ?')->execute([(int) $a->admin['id'], (int) $a->member['id'], $a->orgId]);
            $a->as($a->member);
            $body = $a->request('GET', '/projects/dashboard')['body'];
            LiteAssert::contains('There are no projects you can see yet.', $body);
            LiteAssert::notContains('kpi__value', $body);
        });
        $withMemberScope($a, 'projects.view', null, function () use ($a) {
            $a->as($a->member);
            LiteAssert::same('forbidden', $a->outcome('GET', '/projects/dashboard'));
        });
        $a->setOrgEntitled('projects', false);
        try {
            $a->as($a->admin);
            LiteAssert::same('notfound', $a->outcome('GET', '/projects/dashboard'));
        } finally {
            $a->setOrgEntitled('projects', true);
        }
    },

    'tasks: open / overdue tiles and the overdue-tasks box; completed, cancelled, rejected and closed projects excluded' => function () use ($boot, $project) {
        $a = $boot();
        $svc = $a->container()->make(TaskService::class);
        $open = $project($a, 'P3 task project', 'active', '2026-12-31');
        $closed = $project($a, 'P3 task closed project', 'completed', '2026-01-01');
        $mk = fn (int $pid, string $title, ?string $due, ?string $status = null) => (function () use ($svc, $a, $pid, $title, $due, $status) {
            $t = $svc->create($a->admin, ['title' => $title, 'related_type' => 'project', 'related_id' => (string) $pid] + ($due !== null ? ['due_at' => $due] : []));
            if ($status !== null) {
                $svc->changeStatus($a->admin, (int) $t['id'], $status);
            }
        })();
        $mk($open, 'P3T overdue', '2020-01-01');
        $mk($open, 'P3T future', '2099-01-01');
        $mk($open, 'P3T no due', null);
        $mk($open, 'P3T done late', '2020-01-01', 'completed');
        $mk($open, 'P3T cancelled late', '2020-01-01', 'cancelled');
        $mk($open, 'P3T rejected late', '2020-01-01', 'rejected');
        $mk($closed, 'P3T in closed project', '2020-01-01');

        LiteAssert::same([$open => ['open' => 3, 'overdue' => 1]], $svc->deadlinesForProjects($a->admin, [$open]));
        // Same overdue rule as the Phase 1 panel (summaryFor()).
        LiteAssert::same($svc->summaryFor($svc->visibleForSubject($a->admin, 'project', $open))['overdue'], $svc->deadlinesForProjects($a->admin, [$open])[$open]['overdue']);

        $body = $a->request('GET', '/projects/dashboard')['body'];
        LiteAssert::contains('Open tasks', $body);
        LiteAssert::contains('Overdue tasks', $body);
        LiteAssert::contains('data-related-panel="tasks.project_dashboard"', $body);
        LiteAssert::contains("href=\"/tasks/projects/{$open}/board\"", $body);
        LiteAssert::notContains("/tasks/projects/{$closed}/board", $body, 'closed project not listed');
    },

    'tasks: own task scope counts only the viewer\'s tasks' => function () use ($boot, $project, $withMemberScope) {
        $a = $boot();
        $svc = $a->container()->make(TaskService::class);
        $p = $project($a, 'P3 scoped tasks', 'active', '2026-12-31');
        $svc->create($a->admin, ['title' => 'P3T admin late', 'related_type' => 'project', 'related_id' => (string) $p, 'due_at' => '2020-01-01', 'assigned_to_user_ids' => [(string) $a->admin['id']]]);
        $svc->create($a->admin, ['title' => 'P3T member open', 'related_type' => 'project', 'related_id' => (string) $p, 'assigned_to_user_ids' => [(string) $a->member['id']]]);
        LiteAssert::same(['open' => 2, 'overdue' => 1], $svc->deadlinesForProjects($a->admin, [$p])[$p]);
        $withMemberScope($a, 'tasks.view', 'own', function () use ($a, $svc, $p) {
            LiteAssert::same(['open' => 1, 'overdue' => 0], $svc->deadlinesForProjects($a->member, [$p])[$p]);
        });
        $withMemberScope($a, 'tasks.view', null, function () use ($a, $svc, $p) {
            LiteAssert::same([], $svc->deadlinesForProjects($a->member, [$p]));
            $a->as($a->member);
            LiteAssert::notContains('Open tasks', $a->request('GET', '/projects/dashboard')['body'], 'no task tiles without tasks.view');
        });
    },

    'Sales not available: the dashboard works without task tiles, task box or progress column' => function () use ($boot, $project) {
        $a = $boot();
        $project($a, 'P3 no sales late', 'active', '2020-01-01');
        $a->setOrgEntitled('sales', false);
        try {
            $a->as($a->admin);
            $r = $a->request('GET', '/projects/dashboard');
            LiteAssert::same(200, $r['status']);
            LiteAssert::contains('P3 no sales late', $r['body']);
            LiteAssert::contains('Overdue projects', $r['body']);
            LiteAssert::notContains('Open tasks', $r['body']);
            LiteAssert::notContains('tasks.project_dashboard', $r['body']);
            LiteAssert::notContains('project-list__extra', $r['body']);
        } finally {
            $a->setOrgEntitled('sales', true);
        }
    },

    'efficiency: the same number of queries for 1 or 12 projects (overview, task deadlines, whole page)' => function () use ($boot, $dash, $project, $milestone, $questions) {
        $a = $boot();
        $svc = $a->container()->make(TaskService::class);
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $id = $project($a, "P3 perf {$i}", 'active', '2020-01-01');
            $milestone($a, $id, '2020-01-02');
            $svc->create($a->admin, ['title' => "P3T perf {$i}", 'related_type' => 'project', 'related_id' => (string) $id, 'due_at' => '2020-01-01']);
        }
        $count = function (callable $fn) use ($a, $questions): int {
            $before = $questions($a);
            $fn();

            return $questions($a) - $before;
        };
        $one = $count(fn () => $svc->deadlinesForProjects($a->admin, [$ids[0]]));
        $many = $count(fn () => $svc->deadlinesForProjects($a->admin, $ids));
        LiteAssert::true($one > 0, 'the counter sees the app connection');
        LiteAssert::same($one, $many, 'task deadlines');

        $page1 = $count(fn () => $a->request('GET', '/projects/dashboard'));
        for ($i = 12; $i < 24; $i++) {
            $id = $project($a, "P3 perf {$i}", 'active', '2020-01-01');
            $milestone($a, $id, '2020-01-02');
            $svc->create($a->admin, ['title' => "P3T perf {$i}", 'related_type' => 'project', 'related_id' => (string) $id, 'due_at' => '2020-01-01']);
        }
        $page2 = $count(fn () => $a->request('GET', '/projects/dashboard'));
        LiteAssert::same($page1, $page2, "dashboard page queries with 12 vs 24 late projects ({$page1} / {$page2})");
    },

    'translations: every new key in all five languages; the page renders with no raw keys' => function () use ($boot, $project, $milestone) {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $new = array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'projects.dashboard.') || str_starts_with($k, 'tasks.project_dashboard.'));
        LiteAssert::true(count($new) >= 25, 'keys found: ' . count($new));
        foreach (['fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require $root . "/lang/{$locale}.php";
            foreach ($new as $key) {
                LiteAssert::true(isset($lang[$key]) && $lang[$key] !== '', "{$locale}: {$key}");
            }
        }
        $a = $boot();
        $p = $project($a, 'P3 i18n', 'active', '2020-01-01');
        $milestone($a, $p, '2020-01-02');
        $milestone($a, $p, (new \DateTimeImmutable())->modify('+3 days')->format('Y-m-d'));
        $a->container()->make(TaskService::class)->create($a->admin, ['title' => 'P3T i18n', 'related_type' => 'project', 'related_id' => (string) $p, 'due_at' => '2020-01-01']);
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $body = $a->request('GET', '/projects/dashboard')['body'];
            foreach (['projects.dashboard.', 'tasks.project_dashboard.', 'projects.milestones.', 'tasks.project_panel.', 'tasks.project_list.', 'tasks.project_board.'] as $prefix) {
                LiteAssert::notContains($prefix, $body, "{$locale}: raw key {$prefix}");
            }
        }
        $a->locale('en');
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'P3 %'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM tasks WHERE title LIKE 'P3T %'")->fetchColumn());
    },
];
