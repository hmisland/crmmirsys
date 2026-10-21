<?php

declare(strict_types=1);

/**
 * Project Management Phase 1 — milestones (Projects module), the project
 * Overview card, and the Task Manager's "Tasks" panel on a project page
 * (Sales, through RelatedPanelRegistry only). Through the real router +
 * middleware. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use Modules\Sales\Services\TaskService;
use Tests\Lite\Support\LocalApp;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter();
$boot = function () use ($projectsBoot): LocalApp {
    $a = $projectsBoot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'project_milestones'")->fetchColumn() !== 1) {
        throw new LiteSkip('table project_milestones missing (run the Projects module migrations)');
    }
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'sales'")->fetchColumn() === 0) {
        ProjectsLite::activate($a, 'sales', 'Sales');
    }
    $a->setOrgEntitled('sales', true);
    $a->as($a->admin);

    return $a;
};
$idFrom = fn (?string $location): int => (int) preg_replace('#^/projects/(\d+)$#', '$1', (string) $location);
$newProject = fn (LocalApp $a, string $name = 'LiteTest PM1', array $extra = []): int => $idFrom($a->request('POST', '/projects', [], ['name' => $name] + $extra)['location']);
$milestones = fn (LocalApp $a, int $projectId): array => $a->pdo->query("SELECT * FROM project_milestones WHERE project_id = {$projectId} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
$timelineTypes = fn (LocalApp $a, int $projectId): array => $a->pdo->query("SELECT type FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$projectId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
$tasks = fn (LocalApp $a): TaskService => $a->container()->make(TaskService::class);
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

return [
    'milestones: create, edit, update, delete through the routes; each change on the project timeline' => function () use ($boot, $newProject, $milestones, $timelineTypes) {
        $a = $boot();
        $id = $newProject($a);

        $r = $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Kick-off', 'date' => '2026-03-01']);
        LiteAssert::same(302, $r['status']);
        LiteAssert::same("/projects/{$id}", $r['location']);
        $rows = $milestones($a, $id);
        LiteAssert::same(1, count($rows));
        LiteAssert::same(['Kick-off', '2026-03-01', 'planned', $a->orgId], [$rows[0]['name'], $rows[0]['date'], $rows[0]['status'], (int) $rows[0]['organization_id']]);
        $mid = (int) $rows[0]['id'];

        $form = $a->request('GET', "/projects/{$id}/milestones/{$mid}/edit");
        LiteAssert::same(200, $form['status']);
        LiteAssert::contains('crm-datepicker', $form['body'], 'date uses the datepicker');
        LiteAssert::contains('card--form', $form['body']);

        $a->request('POST', "/projects/{$id}/milestones/{$mid}", [], ['name' => 'Kick-off done', 'date' => '2026-03-02', 'status' => 'completed']);
        $rows = $milestones($a, $id);
        LiteAssert::same(['Kick-off done', '2026-03-02', 'completed'], [$rows[0]['name'], $rows[0]['date'], $rows[0]['status']]);

        $show = $a->request('GET', "/projects/{$id}");
        LiteAssert::contains('data-project-milestones', $show['body']);
        LiteAssert::contains('Kick-off done', $show['body']);
        LiteAssert::contains("/projects/{$id}/milestones/{$mid}/delete", $show['body']);

        $a->request('POST', "/projects/{$id}/milestones/{$mid}/delete");
        LiteAssert::same([], $milestones($a, $id));
        $types = $timelineTypes($a, $id);
        foreach (['milestone_created', 'milestone_updated', 'milestone_deleted'] as $type) {
            LiteAssert::true(in_array($type, $types, true), "timeline has {$type}: " . implode(',', $types));
        }
    },

    'milestones: validation — name and date required, a real date, a known status; nothing written' => function () use ($boot, $newProject, $milestones) {
        $a = $boot();
        $id = $newProject($a);
        foreach ([['name' => '', 'date' => '2026-03-01'], ['name' => 'X', 'date' => ''], ['name' => 'X', 'date' => '2026-02-30'], ['name' => 'X', 'date' => '2026-03-01', 'status' => 'done']] as $bad) {
            LiteAssert::same(302, $a->request('POST', "/projects/{$id}/milestones", [], $bad)['status']);
        }
        LiteAssert::same([], $milestones($a, $id));

        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Ok', 'date' => '2026-03-01']);
        $mid = (int) $milestones($a, $id)[0]['id'];
        $r = $a->request('POST', "/projects/{$id}/milestones/{$mid}", [], ['name' => '', 'date' => 'nope']);
        LiteAssert::same(422, $r['status']);
        LiteAssert::contains('field-error', $r['body']);
        LiteAssert::same('Ok', $milestones($a, $id)[0]['name'], 'unchanged');
    },

    'milestones: authorization follows projects.edit on the project; a closed project is read-only; view-only users see them' => function () use ($boot, $newProject, $milestones) {
        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 admin-owned');
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Seen by member', 'date' => '2026-04-01']);
        $mid = (int) $milestones($a, $id)[0]['id'];

        // Member: projects.view (organization) but projects.edit only on own projects.
        $a->as($a->member);
        $show = $a->request('GET', "/projects/{$id}");
        LiteAssert::same(200, $show['status']);
        LiteAssert::contains('Seen by member', $show['body'], 'milestones visible with projects.view');
        LiteAssert::notContains("action=\"/projects/{$id}/milestones\"", $show['body'], 'no add form');
        LiteAssert::notContains("/milestones/{$mid}/edit", $show['body'], 'no edit link');
        LiteAssert::same('forbidden', $a->outcome('POST', "/projects/{$id}/milestones", [], ['name' => 'Nope', 'date' => '2026-04-02']));
        LiteAssert::same('forbidden', $a->outcome('GET', "/projects/{$id}/milestones/{$mid}/edit"));
        LiteAssert::same('forbidden', $a->outcome('POST', "/projects/{$id}/milestones/{$mid}", [], ['name' => 'Nope', 'date' => '2026-04-02']));
        LiteAssert::same('forbidden', $a->outcome('POST', "/projects/{$id}/milestones/{$mid}/delete"));
        LiteAssert::same(1, count($milestones($a, $id)));

        // The member's own project: allowed.
        $own = $newProject($a, 'LiteTest PM1 member-owned');
        $a->request('POST', "/projects/{$own}/milestones", [], ['name' => 'Mine', 'date' => '2026-04-03']);
        LiteAssert::same(1, count($milestones($a, $own)));
        $a->as($a->admin);

        // Completed project: no add form, writes refused.
        foreach (['start', 'complete'] as $step) {
            $a->request('POST', "/projects/{$id}/status", [], ['action' => $step]);
        }
        LiteAssert::notContains("action=\"/projects/{$id}/milestones\"", $a->request('GET', "/projects/{$id}")['body']);
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Late', 'date' => '2026-05-01']);
        $a->request('POST', "/projects/{$id}/milestones/{$mid}/delete");
        LiteAssert::same(['Seen by member'], array_column($milestones($a, $id), 'name'));
    },

    'milestones: organization isolation and project association' => function () use ($boot, $newProject, $milestones) {
        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 A');
        $other = $newProject($a, 'LiteTest PM1 B');
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Of A', 'date' => '2026-06-01']);
        $mid = (int) $milestones($a, $id)[0]['id'];

        // A milestone is reached only through its own project.
        LiteAssert::same('notfound', $a->outcome('GET', "/projects/{$other}/milestones/{$mid}/edit"));
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$other}/milestones/{$mid}/delete"));
        LiteAssert::same(1, count($milestones($a, $id)));

        $foreign = ProjectsLite::foreignProject($a);
        if ($foreign === null) {
            throw new LiteSkip('needs a second organization in crm_local');
        }
        $a->pdo->prepare("INSERT INTO project_milestones (organization_id, project_id, name, date) VALUES (?, ?, 'XORG milestone', '2026-06-02')")->execute([$a->otherOrgId, $foreign]);
        $foreignMid = (int) $a->pdo->lastInsertId();
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$foreign}/milestones", [], ['name' => 'Into B', 'date' => '2026-06-03']));
        LiteAssert::same('notfound', $a->outcome('GET', "/projects/{$foreign}/milestones/{$foreignMid}/edit"));
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$id}/milestones/{$foreignMid}", [], ['name' => 'Hijack', 'date' => '2026-06-04']));
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$id}/milestones/{$foreignMid}/delete"));
        // Even a foreign row that claims this project's id is never listed or touched.
        $a->pdo->prepare("INSERT INTO project_milestones (organization_id, project_id, name, date) VALUES (?, ?, 'XORG on A', '2026-06-05')")->execute([$a->otherOrgId, $id]);
        $sneaky = (int) $a->pdo->lastInsertId();
        LiteAssert::notContains('XORG on A', $a->request('GET', "/projects/{$id}")['body']);
        LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$id}/milestones/{$sneaky}/delete"));
        LiteAssert::same('XORG milestone', $a->pdo->query("SELECT name FROM project_milestones WHERE id = {$foreignMid}")->fetchColumn());
        LiteAssert::same('XORG on A', $a->pdo->query("SELECT name FROM project_milestones WHERE id = {$sneaky}")->fetchColumn());
    },

    'overview card: status, manager, dates and milestone summary; next planned milestone' => function () use ($boot, $newProject) {
        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 overview', ['start_date' => '2026-01-10', 'expected_end_date' => '2099-12-31']);
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Far future', 'date' => '2099-06-01']);
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Already done', 'date' => '2026-01-15', 'status' => 'completed']);
        $a->locale('en');
        $body = $a->request('GET', "/projects/{$id}")['body'];
        LiteAssert::contains('data-project-overview', $body);
        LiteAssert::contains('Overview', $body);
        LiteAssert::contains('1 of 2 completed', $body);
        LiteAssert::contains('Next milestone', $body);
        LiteAssert::contains('Far future', $body);
    },

    'tasks panel: the project\'s tasks with status, priority, assignees, due date, counts and progress; quick add returns to the project' => function () use ($boot, $newProject, $tasks) {
        $a = $boot();
        $a->locale('en');
        $id = $newProject($a, 'LiteTest PM1 tasks');
        $svc = $tasks($a);
        $svc->create($a->admin, ['title' => 'LiteTask overdue', 'related_type' => 'project', 'related_id' => (string) $id, 'due_at' => '2020-01-01', 'priority' => 'high']);
        $done = $svc->create($a->admin, ['title' => 'LiteTask done', 'related_type' => 'project', 'related_id' => (string) $id]);
        $svc->changeStatus($a->admin, (int) $done['id'], 'completed');
        $svc->create($a->admin, ['title' => 'LiteTask elsewhere']);

        $body = $a->request('GET', "/projects/{$id}")['body'];
        LiteAssert::contains('data-related-panel="tasks.project"', $body);
        LiteAssert::contains('LiteTask overdue', $body);
        LiteAssert::contains('LiteTask done', $body);
        LiteAssert::notContains('LiteTask elsewhere', $body, 'only this project\'s tasks');
        LiteAssert::contains('High', $body, 'priority');
        LiteAssert::contains(trim($a->admin['first_name'] . ' ' . ($a->admin['last_name'] ?? '')), $body, 'assignee');
        LiteAssert::contains('value="50"', $body, '1 of 2 completed = 50% progress');
        LiteAssert::same(2, substr_count($body, '<progress class="progress'), 'progress in the panel and in the overview');

        $summary = $svc->summaryFor($svc->visibleForSubject($a->admin, 'project', $id));
        LiteAssert::same(['total' => 2, 'open' => 1, 'completed' => 1, 'overdue' => 1, 'progress' => 50], $summary);

        // "New task" on the panel: an ordinary task related to the project, back to the project page.
        LiteAssert::contains('name="related_type" value="project"', $body);
        $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTask quick', 'related_type' => 'project', 'related_id' => (string) $id, 'return_to' => "/projects/{$id}"]);
        LiteAssert::same("/projects/{$id}", $r['location']);
        $quick = $a->pdo->query("SELECT related_type, related_id FROM tasks WHERE title = 'LiteTask quick'")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['project', $id], [$quick['related_type'], (int) $quick['related_id']]);
        // No second task system: project tasks are rows of the Task Manager's own table.
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'project\\_task%'")->fetchColumn());
    },

    'tasks panel: uses the Task Manager\'s visibility rules (own scope hides other people\'s tasks; no tasks.view = no panel)' => function () use ($boot, $newProject, $tasks, $withMemberScope) {
        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 visibility');
        $tasks($a)->create($a->admin, ['title' => 'LiteTask admin-only', 'related_type' => 'project', 'related_id' => (string) $id, 'assigned_to_user_ids' => [(string) $a->admin['id']]]);

        $withMemberScope($a, 'tasks.view', 'own', function () use ($a, $id) {
            $a->as($a->member);
            $body = $a->request('GET', "/projects/{$id}")['body'];
            LiteAssert::contains('data-related-panel="tasks.project"', $body);
            LiteAssert::notContains('LiteTask admin-only', $body, 'outside the member\'s task scope');
        });
        $withMemberScope($a, 'tasks.view', 'organization', function () use ($a, $id) {
            $a->as($a->member);
            LiteAssert::contains('LiteTask admin-only', $a->request('GET', "/projects/{$id}")['body']);
        });
        $withMemberScope($a, 'tasks.view', null, function () use ($a, $id) {
            $a->as($a->member);
            $body = $a->request('GET', "/projects/{$id}")['body'];
            LiteAssert::notContains('data-related-panel="tasks.project"', $body);
            LiteAssert::contains('data-project-overview', $body);
        });
    },

    'no tasks: zero counts, 0% progress, empty-state row; summaryFor() of nothing' => function () use ($boot, $newProject, $tasks) {
        $a = $boot();
        $a->locale('en');
        $id = $newProject($a, 'LiteTest PM1 empty');
        $body = $a->request('GET', "/projects/{$id}")['body'];
        LiteAssert::contains('No tasks for this project yet.', $body);
        LiteAssert::contains('value="0"', $body);
        LiteAssert::same(['total' => 0, 'open' => 0, 'completed' => 0, 'overdue' => 0, 'progress' => 0], $tasks($a)->summaryFor([]));
        // Only cancelled/rejected tasks: still no division by zero.
        LiteAssert::same(0, $tasks($a)->summaryFor([['status' => 'cancelled'], ['status' => 'rejected']])['progress']);
        // Overdue: all-day from the next day; timed from the minute after.
        $s = $tasks($a)->summaryFor([
            ['status' => 'pending', 'due_at' => '2026-05-10 00:00:00', 'due_all_day' => 1],
            ['status' => 'pending', 'due_at' => '2026-05-09 00:00:00', 'due_all_day' => 1],
            ['status' => 'in_progress', 'due_at' => '2026-05-10 09:00:00', 'due_all_day' => 0],
            ['status' => 'completed', 'due_at' => '2020-01-01 00:00:00', 'due_all_day' => 1],
        ], '2026-05-10 10:00:00');
        LiteAssert::same(['total' => 4, 'open' => 3, 'completed' => 1, 'overdue' => 2, 'progress' => 25], $s);
    },

    'Sales not available: project page, overview and milestones still work, no Tasks panel or task statistics' => function () use ($boot, $newProject, $milestones) {
        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 no sales');
        $a->setOrgEntitled('sales', false);
        try {
            $a->as($a->admin);
            $r = $a->request('GET', "/projects/{$id}");
            LiteAssert::same(200, $r['status']);
            LiteAssert::contains('data-project-overview', $r['body']);
            LiteAssert::contains('data-project-milestones', $r['body']);
            LiteAssert::notContains('data-related-panel="tasks.project"', $r['body']);
            LiteAssert::notContains('<progress', $r['body'], 'no task statistics');
            $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'Without Sales', 'date' => '2026-07-01']);
            LiteAssert::same(['Without Sales'], array_column($milestones($a, $id), 'name'));
        } finally {
            $a->setOrgEntitled('sales', true);
        }
    },

    'translations: every new key in all five languages; pages render with no raw keys' => function () use ($boot, $newProject, $tasks) {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $new = array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'projects.milestones.') || str_starts_with($k, 'tasks.project_panel.') || $k === 'projects.overview');
        LiteAssert::true(count($new) >= 24, 'keys found: ' . count($new));
        foreach (['fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require $root . "/lang/{$locale}.php";
            foreach ($new as $key) {
                LiteAssert::true(isset($lang[$key]) && $lang[$key] !== '', "{$locale}: {$key}");
            }
        }

        $a = $boot();
        $id = $newProject($a, 'LiteTest PM1 i18n');
        $a->request('POST', "/projects/{$id}/milestones", [], ['name' => 'M', 'date' => '2026-08-01']);
        $mid = (int) $a->pdo->query("SELECT id FROM project_milestones WHERE project_id = {$id}")->fetchColumn();
        $tasks($a)->create($a->admin, ['title' => 'LiteTask i18n', 'related_type' => 'project', 'related_id' => (string) $id]);
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            foreach (["/projects/{$id}", "/projects/{$id}/milestones/{$mid}/edit"] as $path) {
                $body = $a->request('GET', $path)['body'];
                foreach (['projects.milestones.', 'tasks.project_panel.', 'projects.overview'] as $prefix) {
                    LiteAssert::notContains($prefix, $body, "{$locale} {$path}: raw key {$prefix}");
                }
            }
        }
        $a->locale('en');
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest PM1%'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTask%'")->fetchColumn());
    },
];
