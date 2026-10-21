<?php

declare(strict_types=1);

/**
 * Project Management Phase 2 — the Project filter on /tasks, one project's
 * task board (/tasks/projects/{id}/board) and the Progress column on
 * /projects (Sales contributes it through RelatedPanelRegistry). Through
 * the real router + middleware. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

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
$idFrom = fn (?string $location): int => (int) preg_replace('#^/projects/(\d+)$#', '$1', (string) $location);
$newProject = fn (LocalApp $a, string $name): int => $idFrom($a->request('POST', '/projects', [], ['name' => $name])['location']);
$tasks = fn (LocalApp $a): TaskService => $a->container()->make(TaskService::class);
$task = fn (LocalApp $a, string $title, ?int $projectId, array $extra = []): array => $a->container()->make(TaskService::class)
    ->create($a->admin, ['title' => $title] + ($projectId !== null ? ['related_type' => 'project', 'related_id' => (string) $projectId] : []) + $extra);
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
    'task list: Project filter shows only that project\'s tasks, lists visible projects, combines with search, can be cleared' => function () use ($boot, $newProject, $task) {
        $a = $boot();
        $pa = $newProject($a, 'LiteTest P2 Alpha');
        $pb = $newProject($a, 'LiteTest P2 Beta');
        $task($a, 'P2task alpha one', $pa);
        $task($a, 'P2task alpha two', $pa);
        $task($a, 'P2task beta', $pb);
        $task($a, 'P2task loose', null, ['related_type' => 'contact', 'related_id' => (string) ProjectsLite::contactId($a)]);

        $all = $a->request('GET', '/tasks', ['view' => 'all']);
        LiteAssert::contains('name="project_id"', $all['body'], 'selector');
        LiteAssert::contains('LiteTest P2 Alpha', $all['body'], 'option');
        LiteAssert::contains('P2task loose', $all['body'], 'other related types unchanged without the filter');

        $r = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => (string) $pa]);
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('P2task alpha one', $r['body']);
        LiteAssert::contains('P2task alpha two', $r['body']);
        LiteAssert::notContains('P2task beta', $r['body']);
        LiteAssert::notContains('P2task loose', $r['body']);
        LiteAssert::contains('value="' . $pa . '" selected', $r['body']);
        LiteAssert::contains('/tasks?view=all" class="btn btn--ghost"', $r['body'], 'clear link');

        $both = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => (string) $pa, 'q' => 'two']);
        LiteAssert::contains('P2task alpha two', $both['body']);
        LiteAssert::notContains('P2task alpha one', $both['body'], 'search still applies');
    },

    'task list: the Project filter is kept across pages (page 2 holds the rest of the project\'s tasks only)' => function () use ($boot, $newProject, $task) {
        $a = $boot();
        $pa = $newProject($a, 'LiteTest P2 Paged');
        $pb = $newProject($a, 'LiteTest P2 Paged other');
        for ($i = 1; $i <= 27; $i++) {
            $task($a, sprintf('P2task paged %02d', $i), $pa);
        }
        $task($a, 'P2task paged outsider', $pb);
        $page1 = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => (string) $pa])['body'];
        LiteAssert::contains('rel="next"', $page1, '27 tasks > 25 per page');
        LiteAssert::true(preg_match('#href="[^"]*project_id=' . $pa . '[^"]*page=2[^"]*"|href="[^"]*page=2[^"]*project_id=' . $pa . '#', html_entity_decode($page1)) === 1, 'next-page link keeps project_id');
        LiteAssert::same(25, substr_count($page1, 'P2task paged '));
        $page2 = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => (string) $pa, 'page' => '2'])['body'];
        LiteAssert::same(2, substr_count($page2, 'P2task paged '), 'the remaining two, never the other project\'s');
        LiteAssert::notContains('P2task paged outsider', $page1 . $page2);
    },

    'task list: unknown, foreign and inaccessible project ids are ignored and reveal nothing' => function () use ($boot, $newProject, $task, $withMemberScope) {
        $a = $boot();
        $pa = $newProject($a, 'LiteTest P2 Guard');
        $task($a, 'P2task guard', $pa);
        $task($a, 'P2task unrelated', null);
        foreach (['999999999', 'abc', '-1'] as $bad) {
            $r = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => $bad]);
            LiteAssert::same(200, $r['status']);
            LiteAssert::contains('P2task unrelated', $r['body'], "ignored: {$bad}");
        }
        $foreign = ProjectsLite::foreignProject($a);
        if ($foreign !== null) {
            $r = $a->request('GET', '/tasks', ['view' => 'all', 'project_id' => (string) $foreign]);
            LiteAssert::contains('P2task unrelated', $r['body']);
            LiteAssert::notContains('XORG project', $r['body'], 'foreign project never named');
        }
        // A member who may not see the project (projects.view own, not a member).
        $withMemberScope($a, 'projects.view', 'own', function () use ($a, $pa) {
            $a->as($a->member);
            $r = $a->request('GET', '/tasks', ['project_id' => (string) $pa]);
            LiteAssert::same(200, $r['status']);
            LiteAssert::notContains('LiteTest P2 Guard', $r['body'], 'not offered, not named');
            LiteAssert::notContains('P2task guard', $r['body']);
        });
    },

    'board: only this project\'s visible tasks, one column per organization status (custom ones too), task links' => function () use ($boot, $newProject, $task) {
        $a = $boot();
        $pa = $newProject($a, 'LiteTest P2 Board');
        $pb = $newProject($a, 'LiteTest P2 Other');
        $a->pdo->prepare("INSERT INTO task_statuses (organization_id, name, color, category, sort_order, is_active) VALUES (?, 'P2 Review', 'blue', 'in_progress', 25, 1)")->execute([$a->orgId]);
        $mine = $task($a, 'P2task on board', $pa, ['priority' => 'high', 'due_at' => '2026-09-01']);
        $task($a, 'P2task elsewhere', $pb);

        $r = $a->request('GET', "/tasks/projects/{$pa}/board");
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('class="kanban"', $r['body']);
        LiteAssert::contains('P2task on board', $r['body']);
        LiteAssert::notContains('P2task elsewhere', $r['body']);
        LiteAssert::contains('P2 Review', $r['body'], 'custom status column');
        LiteAssert::contains('href="/tasks/' . $mine['id'] . '"', $r['body']);
        LiteAssert::contains('High', $r['body']);
        LiteAssert::contains('action="/tasks/' . $mine['id'] . '/status"', $r['body']);
        LiteAssert::contains("value=\"/tasks/projects/{$pa}/board\"", $r['body'], 'returns to the board');

        // Entry point in the project's Tasks panel.
        LiteAssert::contains("href=\"/tasks/projects/{$pa}/board\"", $a->request('GET', "/projects/{$pa}")['body']);
        // Empty project: renders, with the empty message.
        $empty = $newProject($a, 'LiteTest P2 Empty board');
        $e = $a->request('GET', "/tasks/projects/{$empty}/board");
        LiteAssert::same(200, $e['status']);
        LiteAssert::contains('No tasks for this project yet.', $e['body']);
    },

    'board: unknown, foreign and inaccessible projects are 404' => function () use ($boot, $newProject, $withMemberScope) {
        $a = $boot();
        LiteAssert::same('notfound', $a->outcome('GET', '/tasks/projects/999999999/board'));
        $foreign = ProjectsLite::foreignProject($a);
        if ($foreign !== null) {
            LiteAssert::same('notfound', $a->outcome('GET', "/tasks/projects/{$foreign}/board"));
        }
        $pa = $newProject($a, 'LiteTest P2 Hidden');
        $withMemberScope($a, 'projects.view', 'own', function () use ($a, $pa) {
            $a->as($a->member);
            LiteAssert::same('notfound', $a->outcome('GET', "/tasks/projects/{$pa}/board"));
        });
    },

    'board: moving a card uses the existing status route (CSRF, RBAC, validation)' => function () use ($boot, $newProject, $task, $withMemberScope) {
        $a = $boot();
        $pa = $newProject($a, 'LiteTest P2 Move');
        $t = $task($a, 'P2task move', $pa);
        $done = (int) $a->pdo->query("SELECT id FROM task_statuses WHERE organization_id = {$a->orgId} AND category = 'completed' AND is_active = 1 ORDER BY sort_order LIMIT 1")->fetchColumn();
        $board = "/tasks/projects/{$pa}/board";

        LiteAssert::same('csrf', $a->outcome('POST', "/tasks/{$t['id']}/status", [], ['status_id' => (string) $done, 'return_to' => $board, '_csrf' => 'bad']));
        $r = $a->request('POST', "/tasks/{$t['id']}/status", [], ['status_id' => (string) $done, 'return_to' => $board]);
        LiteAssert::same($board, $r['location']);
        LiteAssert::same('completed', $a->pdo->query("SELECT status FROM tasks WHERE id = {$t['id']}")->fetchColumn());

        // Another organization's status id is refused by the existing validation.
        $a->request('POST', "/tasks/{$t['id']}/status", [], ['status_id' => '999999999', 'return_to' => $board]);
        LiteAssert::same('completed', $a->pdo->query("SELECT status FROM tasks WHERE id = {$t['id']}")->fetchColumn());

        $withMemberScope($a, 'tasks.change_status', null, function () use ($a, $t, $board, $pa) {
            $a->as($a->member);
            LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t['id']}/status", [], ['status_id' => '1', 'return_to' => $board]));
            LiteAssert::notContains("action=\"/tasks/{$t['id']}/status\"", $a->request('GET', "/tasks/projects/{$pa}/board")['body'], 'no move control without the permission');
        });
    },

    'projects list: Progress column = the Phase 1 definition (completed / not rejected-or-cancelled; 0% without tasks)' => function () use ($boot, $newProject, $task, $tasks) {
        $a = $boot();
        $p1 = $newProject($a, 'LiteTest P2 Prog A');
        $p2 = $newProject($a, 'LiteTest P2 Prog B');
        $p3 = $newProject($a, 'LiteTest P2 Prog C');
        $svc = $tasks($a);
        foreach (['completed', 'pending', 'cancelled', 'rejected', 'in_progress'] as $i => $status) {
            $t = $task($a, "P2task prog {$i}", $p1);
            if ($status !== 'pending') {
                $svc->changeStatus($a->admin, (int) $t['id'], $status);
            }
        }
        foreach (['cancelled', 'rejected'] as $i => $status) {
            $svc->changeStatus($a->admin, (int) $task($a, "P2task dropped {$i}", $p2)['id'], $status);
        }

        $progress = $svc->progressForProjects($a->admin, [$p1, $p2, $p3]);
        LiteAssert::same([$p1 => 33, $p2 => 0, $p3 => 0], $progress, '1 of 3 counted = 33%');
        foreach ([$p1, $p2, $p3] as $id) {
            LiteAssert::same($svc->summaryFor($svc->visibleForSubject($a->admin, 'project', $id))['progress'], $progress[$id], "same as the Tasks panel for #{$id}");
        }

        $body = $a->request('GET', '/projects', ['q' => 'LiteTest P2 Prog'])['body'];
        LiteAssert::contains('<th>Progress</th>', $body);
        LiteAssert::contains('value="33"', $body);
        LiteAssert::same(3, substr_count($body, 'class="project-list__extra"'));
    },

    'projects list: progress is one grouped query for the whole page (no N+1)' => function () use ($boot, $newProject, $task, $tasks, $questions) {
        $a = $boot();
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $id = $newProject($a, "LiteTest P2 N1 {$i}");
            $task($a, "P2task n1 {$i}", $id);
        }
        $svc = $tasks($a);
        $before = $questions($a);
        $svc->progressForProjects($a->admin, array_slice($ids, 0, 1));
        $one = $questions($a) - $before;
        $before = $questions($a);
        $svc->progressForProjects($a->admin, $ids);
        $six = $questions($a) - $before;
        LiteAssert::true($one > 0, "the counter sees the app connection ({$one})");
        LiteAssert::same($one, $six, "queries for 1 project ({$one}) and for 6 projects ({$six})");
    },

    'projects list: narrow tasks.view scope counts only the viewer\'s tasks, same as the panel' => function () use ($boot, $newProject, $task, $tasks, $withMemberScope) {
        $a = $boot();
        $p = $newProject($a, 'LiteTest P2 Scope');
        $svc = $tasks($a);
        $svc->changeStatus($a->admin, (int) $task($a, 'P2task admin done', $p, ['assigned_to_user_ids' => [(string) $a->admin['id']]])['id'], 'completed');
        $task($a, 'P2task member open', $p, ['assigned_to_user_ids' => [(string) $a->member['id']]]);
        LiteAssert::same(50, $svc->progressForProjects($a->admin, [$p])[$p]);
        $withMemberScope($a, 'tasks.view', 'own', function () use ($a, $svc, $p) {
            $a->as($a->member);
            $member = $a->member;
            LiteAssert::same(0, $svc->progressForProjects($member, [$p])[$p], 'only the member\'s own open task counts');
            LiteAssert::same($svc->summaryFor($svc->visibleForSubject($member, 'project', $p))['progress'], $svc->progressForProjects($member, [$p])[$p]);
        });
    },

    'Sales not available: projects list without Progress column, project page without board link, board route gone' => function () use ($boot, $newProject) {
        $a = $boot();
        $p = $newProject($a, 'LiteTest P2 NoSales');
        $a->setOrgEntitled('sales', false);
        try {
            $a->as($a->admin);
            $list = $a->request('GET', '/projects');
            LiteAssert::same(200, $list['status']);
            LiteAssert::notContains('<th>Progress</th>', $list['body']);
            LiteAssert::notContains('project-list__extra', $list['body']);
            $show = $a->request('GET', "/projects/{$p}");
            LiteAssert::same(200, $show['status']);
            LiteAssert::notContains('/board"', $show['body']);
            LiteAssert::same('notfound', $a->outcome('GET', "/tasks/projects/{$p}/board"));
        } finally {
            $a->setOrgEntitled('sales', true);
        }
    },

    'translations: new keys in all five languages; pages render with no raw keys' => function () use ($boot, $newProject, $task) {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $new = array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'tasks.project_filter.') || str_starts_with($k, 'tasks.project_board.') || str_starts_with($k, 'tasks.project_list.'));
        LiteAssert::true(count($new) >= 9, 'keys found: ' . count($new));
        foreach (['fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require $root . "/lang/{$locale}.php";
            foreach ($new as $key) {
                LiteAssert::true(isset($lang[$key]) && $lang[$key] !== '', "{$locale}: {$key}");
            }
        }
        $a = $boot();
        $p = $newProject($a, 'LiteTest P2 i18n');
        $task($a, 'P2task i18n', $p);
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            foreach (['/tasks?project_id=' . $p => ['/tasks', ['project_id' => (string) $p]], 'board' => ["/tasks/projects/{$p}/board", []], 'list' => ['/projects', []]] as $label => [$path, $query]) {
                $body = $a->request('GET', $path, $query)['body'];
                foreach (['tasks.project_filter.', 'tasks.project_board.', 'tasks.project_list.'] as $prefix) {
                    LiteAssert::notContains($prefix, $body, "{$locale} {$label}: raw key {$prefix}");
                }
            }
        }
        $a->locale('en');
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest P2%'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM tasks WHERE title LIKE 'P2task%'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM task_statuses WHERE name = 'P2 Review'")->fetchColumn());
    },
];
