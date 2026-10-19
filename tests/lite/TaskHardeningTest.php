<?php

declare(strict_types=1);

/**
 * Task Manager Phase 0 — security and correctness hardening (Sales tasks).
 *
 * P0-1 assignees     P0-2 comment/attachment deletion   P0-3 related record
 * P0-4 visibility    P0-5 lifecycle + tasks.reopen      P0-6 soft delete
 * P0-7 return_to     P0-8 atomic create                 P0-9 due date / input
 *
 * crm_local only, one transaction, rolled back (the atomic-create test runs
 * after that rollback on purpose and removes anything it might leave);
 * attachment files written by the suite are removed at the end.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\Permission;
use Modules\Sales\Controllers\TaskController;
use Modules\Sales\Services\TaskService;
use Tests\Lite\Support\LocalApp;
use Tests\Lite\Support\ProjectsLite;

$h = new \stdClass();
$h->app = null;
$h->reason = '';
$h->files = [];
$h->f = null;
register_shutdown_function(function () use ($h) {
    foreach ($h->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

/** The member role's task keys, set explicitly so the suite never depends on local grants. */
$baseline = [
    'tasks.view' => 'organization', 'tasks.create' => 'organization', 'tasks.edit' => 'own',
    'tasks.change_status' => 'organization', 'tasks.archive' => 'organization',
    'tasks.delete' => null, 'tasks.reopen' => null, 'deals.view' => 'organization', 'contacts.view' => 'organization',
];
$setScopes = function (LocalApp $a, array $scopes): void {
    foreach ($scopes as $key => $scope) {
        $a->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$a->memberRoleId, $key]);
        if ($scope !== null) {
            $a->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?)')->execute([$a->memberRoleId, $key, $scope]);
        }
    }
};
/** Member scopes changed for $fn only, then back to the baseline. */
$withScopes = function (LocalApp $a, array $scopes, callable $fn) use ($setScopes, $baseline): void {
    $setScopes($a, $scopes);
    try {
        $fn();
    } finally {
        $setScopes($a, array_intersect_key($baseline, $scopes) + array_fill_keys(array_keys(array_diff_key($scopes, $baseline)), null));
        $a->as($a->admin);
    }
};

$boot = function () use ($h, $setScopes, $baseline): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    if ($a->member === null) {
        $a->rollBack();
        $h->reason = 'needs a second active user in the organization';
        throw new LiteSkip($h->reason);
    }
    $hasColumn = (int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'deleted_at'")->fetchColumn();
    if ($hasColumn !== 1) {
        $a->rollBack();
        $h->reason = 'Sales migration 0007_add_soft_delete_to_tasks not applied to crm_local';
        throw new LiteSkip($h->reason);
    }
    $sales = $a->pdo->query("SELECT id FROM modules WHERE `key` = 'sales'")->fetchColumn();
    if ($sales === false) {
        $a->rollBack();
        $h->reason = 'module sales not installed in crm_local';
        throw new LiteSkip($h->reason);
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $sales]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $sales]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $sales]);
    $setScopes($a, $baseline);

    // Fixtures of this organization: two teams and a department, a colleague
    // (team 1 + department) and an outsider (team 2), a deal and a contact.
    $insert = function (string $sql, array $params) use ($a): int {
        $a->pdo->prepare($sql)->execute($params);

        return (int) $a->pdo->lastInsertId();
    };
    $f = new \stdClass();
    $f->team1 = $insert('INSERT INTO teams (organization_id, name) VALUES (?, ?)', [$a->orgId, 'LiteTest TH team 1']);
    $f->team2 = $insert('INSERT INTO teams (organization_id, name) VALUES (?, ?)', [$a->orgId, 'LiteTest TH team 2']);
    $f->dept = $insert('INSERT INTO departments (organization_id, name) VALUES (?, ?)', [$a->orgId, 'LiteTest TH dept']);
    $user = fn (string $name, ?int $team, ?int $dept) => $insert(
        "INSERT INTO users (organization_id, first_name, last_name, email, password_hash, status, team_id, department_id) VALUES (?, ?, 'LiteTest', ?, 'x', 'active', ?, ?)",
        [$a->orgId, $name, 'litetest-th-' . bin2hex(random_bytes(4)) . '@example.test', $team, $dept]
    );
    $f->colleague = $user('LiteTestColleague', $f->team1, $f->dept);
    $f->outsider = $user('LiteTestOutsider', $f->team2, null);
    $pipeline = $insert('INSERT INTO pipelines (organization_id, name) VALUES (?, ?)', [$a->orgId, 'LiteTest TH pipeline']);
    $stage = $insert('INSERT INTO pipeline_stages (organization_id, pipeline_id, name) VALUES (?, ?, ?)', [$a->orgId, $pipeline, 'LiteTest TH stage']);
    $f->deal = $insert('INSERT INTO deals (organization_id, pipeline_id, stage_id, name, owner_id) VALUES (?, ?, ?, ?, ?)', [$a->orgId, $pipeline, $stage, 'LiteTest TH deal', (int) $a->admin['id']]);
    $f->contact = $insert('INSERT INTO contacts (organization_id, first_name, last_name) VALUES (?, ?, ?)', [$a->orgId, 'LiteTestTH', 'Contact']);

    $f->foreignUser = null;
    $f->foreignDeal = null;
    $f->foreignContact = null;
    if ($a->otherOrgId !== null) {
        $f->foreignUser = $insert("INSERT INTO users (organization_id, first_name, last_name, email, password_hash, status) VALUES (?, 'LiteTestXorg', 'Person', ?, 'x', 'active')", [$a->otherOrgId, 'litetest-th-x-' . bin2hex(random_bytes(4)) . '@example.test']);
        $xp = $insert('INSERT INTO pipelines (organization_id, name) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest TH xorg pipeline']);
        $xs = $insert('INSERT INTO pipeline_stages (organization_id, pipeline_id, name) VALUES (?, ?, ?)', [$a->otherOrgId, $xp, 'LiteTest TH xorg stage']);
        $f->foreignDeal = $insert('INSERT INTO deals (organization_id, pipeline_id, stage_id, name) VALUES (?, ?, ?, ?)', [$a->otherOrgId, $xp, $xs, 'LiteTest TH xorg deal']);
        $f->foreignContact = $insert('INSERT INTO contacts (organization_id, first_name, last_name) VALUES (?, ?, ?)', [$a->otherOrgId, 'LiteTestTHX', 'Contact']);
    }

    $a->as($a->admin);
    $h->f = $f;
    $h->app = $a;

    return $a;
};
$svc = fn (LocalApp $a): TaskService => $a->container()->make(TaskService::class);
$row = fn (LocalApp $a, int $id): array|false => $a->pdo->query("SELECT * FROM tasks WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
$count = fn (LocalApp $a, string $sql): int => (int) $a->pdo->query($sql)->fetchColumn();
$assignees = function (LocalApp $a, int $taskId): array {
    $ids = array_map('intval', $a->pdo->query("SELECT user_id FROM task_assignees WHERE task_id = {$taskId} ORDER BY user_id")->fetchAll(\PDO::FETCH_COLUMN));
    sort($ids);

    return $ids;
};
/** A raw task row (+ assignee rows). The first assignee is the primary one. */
$mk = function (LocalApp $a, string $title, int $creatorId, array $assigneeIds = [], array $extra = []): int {
    $cols = array_merge([
        'organization_id' => $a->orgId, 'title' => $title, 'created_by_user_id' => $creatorId,
        'assigned_to_user_id' => $assigneeIds[0] ?? null,
    ], $extra);
    $a->pdo->prepare('INSERT INTO tasks (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($cols));
    $id = (int) $a->pdo->lastInsertId();
    foreach ($assigneeIds as $userId) {
        $a->pdo->prepare('INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)')->execute([$id, $userId]);
    }

    return $id;
};
$note = function (LocalApp $a, int $taskId, int $userId, string $body): int {
    $a->pdo->prepare("INSERT INTO notes (organization_id, notable_type, notable_id, user_id, body) VALUES (?, 'task', ?, ?, ?)")->execute([$a->orgId, $taskId, $userId, $body]);

    return (int) $a->pdo->lastInsertId();
};
/** An attachment row + a real stored file; returns [id, absolute path]. */
$attachment = function (LocalApp $a, int $taskId, int $userId) use ($h): array {
    $stored = $a->orgId . '/litetest-th-' . bin2hex(random_bytes(8)) . '.txt';
    $a->pdo->prepare("INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, 'task', ?, ?, 'litetest.txt', ?, 'text/plain', 6)")
        ->execute([$a->orgId, $taskId, $userId, $stored]);
    $id = (int) $a->pdo->lastInsertId();
    $path = $a->container()->make(\App\Application\Services\AttachmentService::class)->absolutePath(['stored_path' => $stored]);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'secret');
    $h->files[] = $path;

    return [$id, $path];
};
/** Asserts a ValidationException naming $field. */
$invalid = function (callable $fn, string $field, string $label = '') {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "{$label}: error on {$field}, got " . json_encode(array_keys($e->errors)));

        return;
    }
    throw new \RuntimeException("{$label}: expected a ValidationException on {$field}");
};
$member = fn (LocalApp $a, array $extra = []): array => array_merge($a->member, $extra);

$tests = [];

// ---- P0-1 assignees ----------------------------------------------------------
$tests['P0-1.1 another organization\'s user, unknown or malformed ids → rejected, nothing written (service + route)'] = function () use ($boot, $h, $svc, $count, $invalid) {
    $a = $boot();
    $f = $h->f;
    $bad = ['unknown' => '999999999', 'zero' => '0', 'negative' => '-3', 'text' => 'abc', 'float' => '1.5'];
    if ($f->foreignUser !== null) {
        $bad['foreign'] = (string) $f->foreignUser;
    }
    foreach ($bad as $label => $value) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH bad {$label}", 'assigned_to_user_ids' => [(string) $a->member['id'], $value]]), 'assigned_to_user_ids', $label);
        LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title = 'LiteTest TH bad {$label}'"), "{$label}: no task");
        $r = $a->request('POST', '/tasks', [], ['title' => "LiteTest TH route {$label}", 'assigned_to_user_ids' => [$value], 'return_to' => '/tasks']);
        // T1: a failed create from the task list re-renders it (422) with the dialog open — still no 500.
        LiteAssert::same([422, null], [$r['status'], $r['location']], "{$label}: route answers 422, no 500");
        LiteAssert::contains('id="task-create-dialog" aria-labelledby="task-create-title" data-autoopen open', $r['body'], "{$label}: dialog open with the error");
        LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title = 'LiteTest TH route {$label}'"), "{$label}: route wrote nothing");
    }
    if ($f->foreignUser !== null) {
        LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM task_assignees WHERE user_id = {$f->foreignUser}"), 'no assignee row for the foreign user');
    }
};

$tests['P0-1.2 valid active users are stored (primary = first); nothing submitted = the acting user'] = function () use ($boot, $h, $svc, $assignees) {
    $a = $boot();
    $t = $svc($a)->create($a->admin, ['title' => 'LiteTest TH valid', 'assigned_to_user_ids' => [(string) $a->member['id'], (string) $h->f->colleague]]);
    $expected = [(int) $a->member['id'], $h->f->colleague];
    sort($expected);
    LiteAssert::same($expected, $assignees($a, (int) $t['id']));
    LiteAssert::same((int) $a->member['id'], (int) $t['assigned_to_user_id']);
    $self = $svc($a)->create($a->admin, ['title' => 'LiteTest TH self']);
    LiteAssert::same([(int) $a->admin['id']], $assignees($a, (int) $self['id']));
};

$tests['P0-1.3 inactive user: refused as a NEW assignee; an existing assignment is kept, marked, and can be explicitly reassigned'] = function () use ($boot, $h, $svc, $mk, $assignees, $invalid) {
    $a = $boot();
    $colleague = $h->f->colleague;
    $kept = $mk($a, 'LiteTest TH kept inactive', (int) $a->admin['id'], [$colleague]);
    $other = $mk($a, 'LiteTest TH other', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $a->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$colleague]);
    try {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest TH new inactive', 'assigned_to_user_ids' => [(string) $colleague]]), 'assigned_to_user_ids', 'create');
        $invalid(fn () => $svc($a)->update($a->admin, $other, ['title' => 'x', 'assigned_to_user_ids' => [(string) $colleague]]), 'assigned_to_user_ids', 'add to another task');
        LiteAssert::same([(int) $a->admin['id']], $assignees($a, $other), 'other task unchanged');

        // The existing assignment survives deactivation and an edit that keeps it.
        LiteAssert::same([$colleague], $assignees($a, $kept), 'not removed on deactivation');
        $svc($a)->update($a->admin, $kept, ['title' => 'LiteTest TH kept inactive', 'assigned_to_user_ids' => [(string) $colleague, (string) $a->admin['id']]]);
        $both = [(int) $a->admin['id'], $colleague];
        sort($both);
        LiteAssert::same($both, $assignees($a, $kept), 'kept on edit');

        // The task page marks it; the form offers the inactive user only on this task.
        $page = $a->request('GET', "/tasks/{$kept}")['body'];
        LiteAssert::contains('LiteTestColleague', $page);
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.assignee_inactive'), $page, 'marked inactive');
        LiteAssert::notContains('value="' . $colleague . '"', $a->request('GET', "/tasks/{$other}")['body'], 'not offered on another task');
        LiteAssert::notContains('value="' . $colleague . '"', $a->request('GET', '/tasks')['body'], 'not offered on the create form');

        // A manager reassigns explicitly.
        $svc($a)->update($a->admin, $kept, ['title' => 'LiteTest TH kept inactive', 'assigned_to_user_ids' => [(string) $a->admin['id']]]);
        LiteAssert::same([(int) $a->admin['id']], $assignees($a, $kept), 'reassigned');
    } finally {
        $a->pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$colleague]);
    }
};

// ---- P0-2 comments / attachments --------------------------------------------
$tests['P0-2.1 comments: author may delete; a non-author viewer may not (403, kept); tasks.delete may'] = function () use ($boot, $mk, $note, $count) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH comments', (int) $a->admin['id'], [(int) $a->member['id']]);
    $byAdmin = $note($a, $t, (int) $a->admin['id'], 'LiteTest admin comment');
    $byMember = $note($a, $t, (int) $a->member['id'], 'LiteTest member comment');
    $byMember2 = $note($a, $t, (int) $a->member['id'], 'LiteTest member comment 2');

    $a->as($a->member);
    LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/comments/{$byAdmin}/delete"), 'member → admin\'s comment');
    LiteAssert::same(1, $count($a, "SELECT COUNT(*) FROM notes WHERE id = {$byAdmin}"), 'kept');
    $page = $a->request('GET', "/tasks/{$t}")['body'];
    LiteAssert::notContains("/comments/{$byAdmin}/delete", $page, 'no delete button on another\'s comment');
    LiteAssert::contains("/comments/{$byMember}/delete", $page, 'delete button on own comment');
    LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$t}/comments/{$byMember}/delete"), 'own comment');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM notes WHERE id = {$byMember}"), 'deleted');

    $a->as($a->admin);
    LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$t}/comments/{$byMember2}/delete"), 'admin (tasks.delete) → member\'s comment');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM notes WHERE id = {$byMember2}"));
};

$tests['P0-2.2 attachments: same author rule; row AND file removed only when allowed'] = function () use ($boot, $mk, $attachment, $count) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH files', (int) $a->admin['id'], [(int) $a->member['id']]);
    [$byAdmin, $adminPath] = $attachment($a, $t, (int) $a->admin['id']);
    [$byMember, $memberPath] = $attachment($a, $t, (int) $a->member['id']);
    [$byMember2, $member2Path] = $attachment($a, $t, (int) $a->member['id']);

    $a->as($a->member);
    LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/attachments/{$byAdmin}/delete"));
    LiteAssert::same([1, true], [$count($a, "SELECT COUNT(*) FROM attachments WHERE id = {$byAdmin}"), is_file($adminPath)], 'row and file kept');
    LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$t}/attachments/{$byMember}/delete"));
    LiteAssert::same([0, false], [$count($a, "SELECT COUNT(*) FROM attachments WHERE id = {$byMember}"), is_file($memberPath)], 'own: row and file removed');
    LiteAssert::same('secret', $a->request('GET', "/tasks/{$t}/attachments/{$byAdmin}/download")['body'], 'download by a viewer still works');

    $a->as($a->admin);
    LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$t}/attachments/{$byMember2}/delete"), 'tasks.delete');
    LiteAssert::same([0, false], [$count($a, "SELECT COUNT(*) FROM attachments WHERE id = {$byMember2}"), is_file($member2Path)]);
};

$tests['P0-2.3 guessed ids: another task\'s comment/file, another organization\'s task → 404, nothing deleted'] = function () use ($boot, $mk, $note, $attachment, $count) {
    $a = $boot();
    $t1 = $mk($a, 'LiteTest TH parent 1', (int) $a->admin['id']);
    $t2 = $mk($a, 'LiteTest TH parent 2', (int) $a->admin['id']);
    $n1 = $note($a, $t1, (int) $a->admin['id'], 'LiteTest parent 1 note');
    [$f1, $p1] = $attachment($a, $t1, (int) $a->admin['id']);
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$t2}/comments/{$n1}/delete"), 'comment via other task');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$t2}/attachments/{$f1}/delete"), 'file via other task');
    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$t2}/attachments/{$f1}/download"), 'download via other task');
    LiteAssert::same([1, 1, true], [$count($a, "SELECT COUNT(*) FROM notes WHERE id = {$n1}"), $count($a, "SELECT COUNT(*) FROM attachments WHERE id = {$f1}"), is_file($p1)]);
    if ($a->otherOrgId !== null) {
        $a->pdo->prepare('INSERT INTO tasks (organization_id, title) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest TH xorg task']);
        $x = (int) $a->pdo->lastInsertId();
        foreach (["/tasks/{$x}/comments/{$n1}/delete", "/tasks/{$x}/attachments/{$f1}/delete", "/tasks/{$x}/status", "/tasks/{$x}/delete", "/tasks/{$x}/comments"] as $path) {
            LiteAssert::same('notfound', $a->outcome('POST', $path, [], ['status' => 'completed', 'body' => 'x']), $path);
        }
        LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$x}"));
        LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE id = {$x} AND (status <> 'pending' OR deleted_at IS NOT NULL)"), 'foreign task untouched');
    }
};

// ---- P0-3 related record -------------------------------------------------------
$tests['P0-3.1 related record: unsupported type, missing/malformed id, unknown or foreign record → rejected, nothing written'] = function () use ($boot, $h, $svc, $count, $invalid) {
    $a = $boot();
    $f = $h->f;
    $cases = [
        'project type' => ['related_type' => 'project', 'related_id' => '1'],
        'unknown type' => ['related_type' => 'widget', 'related_id' => '1'],
        'case' => ['related_type' => 'DEAL', 'related_id' => (string) $f->deal],
        'array type' => ['related_type' => ['deal'], 'related_id' => (string) $f->deal],
        'no id' => ['related_type' => 'deal'],
        'zero id' => ['related_type' => 'deal', 'related_id' => '0'],
        'text id' => ['related_type' => 'deal', 'related_id' => $f->deal . 'x'],
        'unknown deal' => ['related_type' => 'deal', 'related_id' => '999999999'],
        'unknown contact' => ['related_type' => 'contact', 'related_id' => '999999999'],
    ];
    if ($f->foreignDeal !== null) {
        $cases['foreign deal'] = ['related_type' => 'deal', 'related_id' => (string) $f->foreignDeal];
        $cases['foreign contact'] = ['related_type' => 'contact', 'related_id' => (string) $f->foreignContact];
    }
    foreach ($cases as $label => $fields) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH rel {$label}"] + $fields), 'related_type', $label);
        $a->request('POST', '/tasks', [], ['title' => "LiteTest TH relroute {$label}", 'return_to' => '/tasks'] + $fields);
        LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title IN ('LiteTest TH rel {$label}', 'LiteTest TH relroute {$label}')"), $label);
    }
};

$tests['P0-3.2 related record must be viewable: no deals.view / deals.view OWN on someone else\'s deal → rejected; allowed otherwise'] = function () use ($boot, $h, $svc, $withScopes, $member, $invalid) {
    $a = $boot();
    $f = $h->f;
    $ok = $svc($a)->create($a->admin, ['title' => 'LiteTest TH rel ok', 'related_type' => 'deal', 'related_id' => (string) $f->deal]);
    LiteAssert::same(['deal', $f->deal], [$ok['related_type'], (int) $ok['related_id']]);
    $c = $svc($a)->create($a->admin, ['title' => 'LiteTest TH rel contact', 'related_type' => 'contact', 'related_id' => (string) $f->contact]);
    LiteAssert::same('contact', $c['related_type']);
    $withScopes($a, ['deals.view' => null], function () use ($a, $f, $svc, $member, $invalid) {
        $invalid(fn () => $svc($a)->create($member($a), ['title' => 'LiteTest TH rel noview', 'related_type' => 'deal', 'related_id' => (string) $f->deal]), 'related_type', 'no deals.view');
    });
    $withScopes($a, ['deals.view' => 'own'], function () use ($a, $f, $svc, $member, $invalid) {
        $invalid(fn () => $svc($a)->create($member($a), ['title' => 'LiteTest TH rel own', 'related_type' => 'deal', 'related_id' => (string) $f->deal]), 'related_type', 'own scope, admin\'s deal');
    });
    // The related record is fixed at creation; an edit cannot move it.
    $svc($a)->update($a->admin, (int) $ok['id'], ['title' => 'LiteTest TH rel ok', 'related_type' => 'contact', 'related_id' => (string) $f->contact]);
    LiteAssert::same(['deal', $f->deal], [$svc($a)->find($a->admin, (int) $ok['id'])['related_type'], (int) $svc($a)->find($a->admin, (int) $ok['id'])['related_id']]);
};

$tests['P0-3.3 project-related task: needs task scope AND project access (registry); module off → hidden'] = function () use ($boot, $mk, $svc, $withScopes, $member) {
    $a = $boot();
    $exists = (int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'")->fetchColumn();
    if ($exists !== 1 || !$a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class)->has('project')) {
        throw new LiteSkip('Projects not installed in crm_local');
    }
    ProjectsLite::activate($a, 'projects', 'Projects');
    $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'LiteTest TH project', 'active', ?, ?)")
        ->execute([$a->orgId, 'LT-TH-' . bin2hex(random_bytes(3)), (int) $a->admin['id'], (int) $a->admin['id']]);
    $project = (int) $a->pdo->lastInsertId();
    $t = $mk($a, 'LiteTest TH project task', (int) $a->admin['id'], [(int) $a->member['id']], ['related_type' => 'project', 'related_id' => $project, 'due_at' => date('Y-m-d 00:00:00')]);

    LiteAssert::same($t, (int) $svc($a)->find($a->admin, $t)['id'], 'admin sees it');
    $withScopes($a, ['projects.view' => 'organization'], function () use ($a, $t, $svc, $member) {
        $a->as($member($a));
        LiteAssert::same($t, (int) $svc($a)->find($member($a), $t)['id'], 'assignee with project access');
    });
    $withScopes($a, ['projects.view' => null], function () use ($a, $t, $svc, $member) {
        $a->as($member($a));
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->find($member($a), $t), 'no project access → 403 even as assignee');
        LiteAssert::false(in_array($t, array_map(fn ($r) => (int) $r['id'], $svc($a)->forUser($member($a), false)), true), 'not in My tasks');
        LiteAssert::false(in_array($t, array_map(fn ($r) => (int) $r['id'], $svc($a)->upcomingForUser($member($a))), true), 'not on the dashboard');
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/status", [], ['status' => 'completed']), 'no status change either');
    });
    $a->setOrgEntitled('projects', false);
    LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->find($a->admin, $t), 'Projects not entitled → hidden');
    $a->setOrgEntitled('projects', true);
};

// ---- P0-4 visibility ------------------------------------------------------------
$tests['P0-4.1 scopes: OWN = creator/assignee; TEAM/DEPARTMENT = own + their people\'s team/department; ORGANIZATION = all'] = function () use ($boot, $h, $mk, $svc, $withScopes, $member) {
    $a = $boot();
    $f = $h->f;
    $mine = $mk($a, 'LiteTest TH scope mine', (int) $a->member['id']);
    $assigned = $mk($a, 'LiteTest TH scope assigned', (int) $a->admin['id'], [(int) $a->admin['id'], (int) $a->member['id']]);
    $colleagues = $mk($a, 'LiteTest TH scope colleague', $f->colleague);
    $teamAssignee = $mk($a, 'LiteTest TH scope team assignee', (int) $a->admin['id'], [(int) $a->admin['id'], $f->colleague]);
    $outsiders = $mk($a, 'LiteTest TH scope outsider', $f->outsider, [$f->outsider]);
    $sees = function (array $user, int $id) use ($a, $svc): bool {
        try {
            $svc($a)->find($user, $id);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    };
    $matrix = fn (array $user) => array_map(fn (int $id) => $sees($user, $id), [$mine, $assigned, $colleagues, $teamAssignee, $outsiders]);

    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $f, $member, $matrix) {
        LiteAssert::same([true, true, false, false, false], $matrix($member($a, ['team_id' => $f->team1])), 'OWN');
    });
    $withScopes($a, ['tasks.view' => 'team'], function () use ($a, $f, $member, $matrix) {
        LiteAssert::same([true, true, true, true, false], $matrix($member($a, ['team_id' => $f->team1])), 'TEAM 1');
        LiteAssert::same([true, true, false, false, true], $matrix($member($a, ['team_id' => $f->team2])), 'TEAM 2');
        LiteAssert::same([true, true, false, false, false], $matrix($member($a, ['team_id' => null])), 'TEAM, no team → own only');
    });
    $withScopes($a, ['tasks.view' => 'department'], function () use ($a, $f, $member, $matrix) {
        LiteAssert::same([true, true, true, true, false], $matrix($member($a, ['department_id' => $f->dept])), 'DEPARTMENT');
        LiteAssert::same([true, true, false, false, false], $matrix($member($a, ['department_id' => null])), 'DEPARTMENT, none → own only');
    });
    $withScopes($a, ['tasks.view' => 'organization'], function () use ($a, $member, $matrix) {
        LiteAssert::same([true, true, true, true, true], $matrix($member($a)), 'ORGANIZATION');
    });
    $withScopes($a, ['tasks.view' => null], function () use ($a, $member, $matrix, $svc) {
        LiteAssert::same([false, false, false, false, false], $matrix($member($a)), 'no tasks.view');
        LiteAssert::throws(AuthorizationException::class, fn () => $svc($a)->forUser($member($a), false));
    });
};

$tests['P0-4.2 an action needs view on the task too: change_status ORGANIZATION + view OWN cannot touch another\'s task'] = function () use ($boot, $mk, $row, $withScopes, $member) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH action needs view', (int) $a->admin['id']);
    $withScopes($a, ['tasks.view' => 'own', 'tasks.change_status' => 'organization', 'tasks.archive' => 'organization'], function () use ($a, $t, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/status", [], ['status' => 'completed']));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/archive"));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/comments", [], ['body' => 'x']));
    });
    LiteAssert::same(['pending', null], [$row($a, $t)['status'], $row($a, $t)['archived_at']]);
};

$tests['P0-4.3 deal page shows only the deal\'s tasks the user may see'] = function () use ($boot, $h, $mk, $withScopes, $member) {
    $a = $boot();
    $deal = $h->f->deal;
    $mk($a, 'LiteTest TH deal admin only', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'deal', 'related_id' => $deal]);
    $mk($a, 'LiteTest TH deal for member', (int) $a->admin['id'], [(int) $a->member['id']], ['related_type' => 'deal', 'related_id' => $deal]);
    $mk($a, 'LiteTest TH deal deleted', (int) $a->admin['id'], [(int) $a->member['id']], ['related_type' => 'deal', 'related_id' => $deal, 'deleted_at' => date('Y-m-d H:i:s')]);

    $admin = $a->request('GET', "/deals/{$deal}")['body'];
    LiteAssert::contains('LiteTest TH deal admin only', $admin);
    LiteAssert::contains('LiteTest TH deal for member', $admin);
    LiteAssert::notContains('LiteTest TH deal deleted', $admin, 'soft-deleted hidden');
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $deal, $member) {
        $a->as($member($a));
        $page = $a->request('GET', "/deals/{$deal}")['body'];
        LiteAssert::notContains('LiteTest TH deal admin only', $page, 'OWN: not another\'s task');
        LiteAssert::contains('LiteTest TH deal for member', $page, 'OWN: own task');
    });
    $withScopes($a, ['tasks.view' => null, 'tasks.create' => null], function () use ($a, $deal, $member) {
        $a->as($member($a));
        $page = $a->request('GET', "/deals/{$deal}")['body'];
        LiteAssert::notContains('LiteTest TH deal for member', $page, 'no tasks.view: none');
        LiteAssert::notContains('name="related_type" value="deal"', $page, 'no tasks.create: no add form');
    });
};

$tests['P0-4.4 contact page tasks: deleted excluded; OWN = creator or assignee; TEAM falls back to own (never all)'] = function () use ($boot, $h, $mk, $withScopes, $member) {
    $a = $boot();
    $c = $h->f->contact;
    $mk($a, 'LiteTest TH contact admin', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'contact', 'related_id' => $c]);
    $mk($a, 'LiteTest TH contact assigned', (int) $a->admin['id'], [(int) $a->member['id']], ['related_type' => 'contact', 'related_id' => $c]);
    $mk($a, 'LiteTest TH contact deleted', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'contact', 'related_id' => $c, 'deleted_at' => date('Y-m-d H:i:s')]);

    $admin = $a->request('GET', "/contacts/{$c}/related/tasks")['body'];
    LiteAssert::contains('LiteTest TH contact admin', $admin);
    LiteAssert::notContains('LiteTest TH contact deleted', $admin, 'soft-deleted hidden');
    foreach (['own', 'team'] as $scope) {
        $withScopes($a, ['tasks.view' => $scope], function () use ($a, $c, $member, $scope, $h) {
            $a->as($member($a, ['team_id' => $h->f->team1]));
            $page = $a->request('GET', "/contacts/{$c}/related/tasks")['body'];
            LiteAssert::contains('LiteTest TH contact assigned', $page, "{$scope}: assigned task shown");
            LiteAssert::notContains('LiteTest TH contact admin', $page, "{$scope}: another's task hidden");
        });
    }
};

$tests['P0-4.5 the UI offers only actions the server allows (list rows)'] = function () use ($boot, $mk, $withScopes, $member) {
    $a = $boot();
    $done = $mk($a, 'LiteTest TH ui done', (int) $a->member['id'], [(int) $a->member['id']], ['status' => 'completed']);
    $open = $mk($a, 'LiteTest TH ui open', (int) $a->member['id'], [(int) $a->member['id']]);
    $a->as($member($a));
    $page = $a->request('GET', '/tasks')['body'];
    LiteAssert::notContains("/tasks/{$done}/toggle", $page, 'no reopen toggle without tasks.reopen');
    LiteAssert::notContains("/tasks/{$done}/status", $page, 'no status menu on a completed task without tasks.reopen');
    LiteAssert::contains("/tasks/{$open}/toggle", $page, 'open task can be completed');
    LiteAssert::notContains("/tasks/{$open}/delete", $page, 'no delete without tasks.delete');
    $withScopes($a, ['tasks.change_status' => null], function () use ($a, $open, $member) {
        $a->as($member($a));
        LiteAssert::notContains("/tasks/{$open}/toggle", $a->request('GET', '/tasks')['body'], 'no toggle without tasks.change_status');
    });
    $withScopes($a, ['tasks.reopen' => 'own'], function () use ($a, $done, $member) {
        $a->as($member($a));
        LiteAssert::contains("/tasks/{$done}/toggle", $a->request('GET', '/tasks')['body'], 'reopen offered with tasks.reopen');
    });
};

// ---- P0-5 lifecycle ------------------------------------------------------------
$tests['P0-5.1 transitions: closed → closed, same status, unknown status → rejected, unchanged'] = function () use ($boot, $mk, $svc, $row, $invalid) {
    $a = $boot();
    $cases = [['completed', 'rejected'], ['completed', 'cancelled'], ['cancelled', 'completed'], ['rejected', 'cancelled'], ['rejected', 'completed'],
        ['pending', 'pending'], ['in_progress', 'in_progress'], ['pending', 'archived'], ['pending', 'done']];
    foreach ($cases as [$from, $to]) {
        $t = $mk($a, "LiteTest TH tr {$from}-{$to}", (int) $a->admin['id'], [], ['status' => $from]);
        $invalid(fn () => $svc($a)->changeStatus($a->admin, $t, $to), 'status', "{$from} → {$to}");
        LiteAssert::same($from, $row($a, $t)['status'], "{$from} → {$to}: unchanged");
    }
    $t = $mk($a, 'LiteTest TH tr route', (int) $a->admin['id'], [], ['status' => 'completed']);
    $a->request('POST', "/tasks/{$t}/status", [], ['status' => 'rejected', 'return_to' => '/tasks']);
    LiteAssert::same('completed', $row($a, $t)['status'], 'route: unchanged');
};

$tests['P0-5.2 open statuses move freely; completed_at set on completion and cleared after'] = function () use ($boot, $mk, $svc, $row) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH tr flow', (int) $a->admin['id']);
    foreach (['in_progress', 'on_hold', 'in_progress', 'pending', 'completed'] as $to) {
        $svc($a)->changeStatus($a->admin, $t, $to);
        LiteAssert::same($to, $row($a, $t)['status']);
    }
    LiteAssert::true($row($a, $t)['completed_at'] !== null, 'completed_at set');
    $svc($a)->changeStatus($a->admin, $t, 'in_progress'); // reopen (admin holds tasks.reopen)
    LiteAssert::same([null, 'in_progress'], [$row($a, $t)['completed_at'], $row($a, $t)['status']]);
};

$tests['P0-5.3 reopening any closed task (completed, rejected, cancelled) needs tasks.reopen with the task\'s scope'] = function () use ($boot, $mk, $row, $withScopes, $member) {
    $a = $boot();
    $mine = [];
    foreach (['completed', 'rejected', 'cancelled'] as $status) {
        $mine[$status] = $mk($a, "LiteTest TH reopen mine {$status}", (int) $a->member['id'], [(int) $a->member['id']], ['status' => $status]);
    }
    $othersDone = $mk($a, 'LiteTest TH reopen others completed', (int) $a->admin['id'], [(int) $a->admin['id']], ['status' => 'completed']);
    $othersRejected = $mk($a, 'LiteTest TH reopen others rejected', (int) $a->admin['id'], [(int) $a->admin['id']], ['status' => 'rejected']);

    // Without tasks.reopen: no way out of any closed status (route + toggle), nothing changes.
    $a->as($member($a));
    LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$mine['completed']}/toggle"), 'toggle completed → pending');
    foreach ($mine as $status => $id) {
        foreach (['pending', 'in_progress'] as $to) {
            LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$id}/status", [], ['status' => $to]), "{$status} → {$to} without tasks.reopen");
        }
        LiteAssert::same($status, $row($a, $id)['status'], "{$status}: unchanged");
    }
    LiteAssert::notContains("/tasks/{$mine['rejected']}/status", $a->request('GET', '/tasks')['body'], 'no status menu on a rejected task without tasks.reopen');

    // With tasks.reopen (OWN scope): own closed tasks reopen; another's do not.
    $withScopes($a, ['tasks.reopen' => 'own'], function () use ($a, $mine, $othersDone, $othersRejected, $row, $member) {
        $a->as($member($a));
        LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$mine['completed']}/toggle"));
        LiteAssert::same('pending', $row($a, $mine['completed'])['status'], 'completed → pending');
        LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$mine['rejected']}/status", [], ['status' => 'pending']));
        LiteAssert::same('pending', $row($a, $mine['rejected'])['status'], 'rejected → pending');
        LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$mine['cancelled']}/status", [], ['status' => 'pending']));
        LiteAssert::same('pending', $row($a, $mine['cancelled'])['status'], 'cancelled → pending');
        foreach (['completed' => $othersDone, 'rejected' => $othersRejected] as $status => $id) {
            LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$id}/status", [], ['status' => 'pending']), "OWN reopen scope, another's {$status} task");
            LiteAssert::same($status, $row($a, $id)['status']);
        }
    });
    LiteAssert::false(in_array('tasks.reopen', array_column($a->pdo->query("SELECT permission_key FROM role_permissions WHERE role_id = {$a->memberRoleId}")->fetchAll(\PDO::FETCH_ASSOC), 'permission_key'), true), 'member role does not get tasks.reopen');
};

$tests['P0-5.5 open-state transitions (and closing a task) need no tasks.reopen — unchanged'] = function () use ($boot, $mk, $row, $member) {
    $a = $boot();
    $a->as($member($a));
    try {
        $steps = ['in_progress', 'on_hold', 'pending', 'on_hold', 'in_progress', 'pending', 'completed'];
        $t = $mk($a, 'LiteTest TH open flow', (int) $a->member['id'], [(int) $a->member['id']]);
        foreach ($steps as $to) {
            LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$t}/status", [], ['status' => $to]), "→ {$to}");
            LiteAssert::same($to, $row($a, $t)['status'], "→ {$to}");
        }
        foreach (['rejected', 'cancelled'] as $to) {
            $u = $mk($a, "LiteTest TH open to {$to}", (int) $a->member['id'], [(int) $a->member['id']], ['status' => 'in_progress']);
            LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$u}/status", [], ['status' => $to]));
            LiteAssert::same($to, $row($a, $u)['status'], "in_progress → {$to}");
        }
    } finally {
        $a->as($a->admin);
    }
    LiteAssert::same(['completed', 'rejected', 'cancelled'], TaskService::REOPEN_FROM, 'closed statuses');
    LiteAssert::same(['pending', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled'], TaskService::STATUSES, 'all six statuses kept');
};

$tests['P0-5.4 archived task is read-only (edit, status, toggle, comment, delete comment); archive/unarchive still work'] = function () use ($boot, $mk, $note, $svc, $row, $count, $invalid) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH archived', (int) $a->admin['id'], [(int) $a->admin['id']], ['archived_at' => date('Y-m-d H:i:s')]);
    $n = $note($a, $t, (int) $a->admin['id'], 'LiteTest archived note');
    $invalid(fn () => $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH archived changed']), 'task', 'update');
    $invalid(fn () => $svc($a)->changeStatus($a->admin, $t, 'completed'), 'task', 'status');
    $invalid(fn () => $svc($a)->toggleComplete($a->admin, $t), 'task', 'toggle');
    $invalid(fn () => $svc($a)->addComment($a->admin, $t, 'x'), 'task', 'comment');
    $invalid(fn () => $svc($a)->deleteComment($a->admin, $t, $n), 'task', 'delete comment');
    $a->request('POST', "/tasks/{$t}/status", [], ['status' => 'completed']);
    LiteAssert::same(['LiteTest TH archived', 'pending', 1], [$row($a, $t)['title'], $row($a, $t)['status'], $count($a, "SELECT COUNT(*) FROM notes WHERE id = {$n}")]);
    $svc($a)->unarchive($a->admin, $t);
    $svc($a)->changeStatus($a->admin, $t, 'completed');
    LiteAssert::same('completed', $row($a, $t)['status'], 'editable again after restore');
};

// ---- P0-6 soft delete ------------------------------------------------------------
$tests['P0-6.1 delete is soft: row, assignees, comments, files kept; task gone from every route, list and widget'] = function () use ($boot, $mk, $note, $attachment, $svc, $row, $count, $assignees, $member) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH soft', (int) $a->admin['id'], [(int) $a->admin['id'], (int) $a->member['id']], ['due_at' => date('Y-m-d 00:00:00')]);
    $n = $note($a, $t, (int) $a->admin['id'], 'LiteTest soft note');
    [$file, $path] = $attachment($a, $t, (int) $a->admin['id']);

    $r = $a->request('POST', "/tasks/{$t}/delete", [], ['return_to' => "/tasks/{$t}"]);
    LiteAssert::same('/tasks', $r['location'], 'never back to the deleted task\'s page');
    $deleted = $row($a, $t);
    LiteAssert::true($deleted !== false && $deleted['deleted_at'] !== null, 'row kept, marked deleted');
    LiteAssert::same((int) $a->admin['id'], (int) $deleted['deleted_by_user_id']);
    $both = [(int) $a->admin['id'], (int) $a->member['id']];
    sort($both);
    LiteAssert::same($both, $assignees($a, $t), 'assignees kept');
    LiteAssert::same([1, 1, true], [$count($a, "SELECT COUNT(*) FROM notes WHERE id = {$n}"), $count($a, "SELECT COUNT(*) FROM attachments WHERE id = {$file}"), is_file($path)], 'comment and file kept');

    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$t}"));
    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$t}/attachments/{$file}/download"));
    foreach (['', '/toggle', '/status', '/archive', '/unarchive', '/delete', '/comments', "/comments/{$n}/delete", "/attachments/{$file}/delete"] as $suffix) {
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$t}{$suffix}", [], ['title' => 'x', 'status' => 'completed', 'body' => 'x']), "POST {$suffix}");
    }
    LiteAssert::same([null, 'pending', 'LiteTest TH soft'], [$row($a, $t)['archived_at'], $row($a, $t)['status'], $row($a, $t)['title']], 'nothing changed after delete');
    $ids = fn (array $rows) => array_map(fn ($r) => (int) $r['id'], $rows);
    LiteAssert::false(in_array($t, $ids($svc($a)->forUser($a->admin, false)), true), 'My tasks');
    LiteAssert::false(in_array($t, $ids($svc($a)->forUser($a->admin, true)), true), 'archive view');
    LiteAssert::false(in_array($t, $ids($svc($a)->upcomingForUser($member($a))), true), 'dashboard');
    LiteAssert::notContains('LiteTest TH soft', $a->request('GET', '/')['body'], 'dashboard page');
};

$tests['P0-6.2 delete needs tasks.delete on the task (member → 403, kept)'] = function () use ($boot, $mk, $row, $member) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH no delete', (int) $a->member['id'], [(int) $a->member['id']]);
    $a->as($member($a));
    LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/delete"));
    LiteAssert::same(null, $row($a, $t)['deleted_at']);
};

// ---- P0-7 return_to ------------------------------------------------------------
$tests['P0-7.1 return_to: external, protocol-relative, malformed → /tasks; task and deal pages kept'] = function () use ($boot, $mk) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH redirect', (int) $a->admin['id']);
    $bad = ['https://evil.example/x', '//evil.example', '/\\evil.example', '\\\\evil.example', 'javascript:alert(1)', 'evil.example', ' /tasks',
        "/tasks\r\nLocation: https://evil.example", '/tasks@evil.example', '/tasks/../contacts', '/deals/abc', '/deals/1/edit', '/contacts', '/tasks?x=<script>', '/tasks#frag', ''];
    foreach ($bad as $value) {
        LiteAssert::false(TaskController::isSafeReturnTo($value), 'unsafe accepted: ' . json_encode($value));
        $r = $a->request('POST', "/tasks/{$t}/archive", [], ['return_to' => $value]);
        LiteAssert::same('/tasks', $r['location'], json_encode($value));
        $a->request('POST', "/tasks/{$t}/unarchive", [], ['return_to' => '/tasks']);
    }
    $r = $a->request('POST', "/tasks/{$t}/archive", [], ['return_to' => ['/deals/1']]);
    LiteAssert::same('/tasks', $r['location'], 'array value');
    $a->request('POST', "/tasks/{$t}/unarchive");
    foreach (['/tasks', '/tasks?view=archived&status=pending', "/tasks/{$t}", '/deals/42'] as $value) {
        LiteAssert::true(TaskController::isSafeReturnTo($value), $value);
        $r = $a->request('POST', "/tasks/{$t}/archive", [], ['return_to' => $value]);
        LiteAssert::same($value, $r['location'], $value);
        $a->request('POST', "/tasks/{$t}/unarchive");
    }
};

// ---- P0-9 due date and input ------------------------------------------------------
$tests['P0-9.1 due_at: empty → none; real dates normalized; impossible or malformed → rejected, nothing written'] = function () use ($boot, $svc, $count, $invalid) {
    $a = $boot();
    LiteAssert::same(null, $svc($a)->create($a->admin, ['title' => 'LiteTest TH due empty', 'due_at' => ''])['due_at']);
    LiteAssert::same(null, $svc($a)->create($a->admin, ['title' => 'LiteTest TH due missing'])['due_at']);
    LiteAssert::same('2026-03-15 00:00:00', $svc($a)->create($a->admin, ['title' => 'LiteTest TH due date', 'due_at' => '2026-03-15'])['due_at']);
    LiteAssert::same('2028-02-29 09:30:00', $svc($a)->create($a->admin, ['title' => 'LiteTest TH due time', 'due_at' => '2028-02-29 09:30'])['due_at']);
    foreach (['2026-02-30', '2027-02-29', '2026-13-01', '2026-00-10', '2026-1-5', '15/03/2026', '2026-03-15T10:00', 'tomorrow', '2026-03-15 25:00', '۱۴۰۵-۰۱-۰۱'] as $i => $value) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH due bad {$i}", 'due_at' => $value]), 'due_at', $value);
        $a->request('POST', '/tasks', [], ['title' => "LiteTest TH due route {$i}", 'due_at' => $value]);
    }
    $invalid(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest TH due array', 'due_at' => ['2026-03-15']]), 'due_at', 'array');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH due bad%' OR title LIKE 'LiteTest TH due route%' OR title = 'LiteTest TH due array'"));
};

$tests['P0-9.2 update: submitted empty due date / description clears; absent fields stay; title required and ≤ 255'] = function () use ($boot, $mk, $svc, $row, $invalid) {
    $a = $boot();
    $t = $mk($a, 'LiteTest TH upd', (int) $a->admin['id'], [], ['due_at' => '2026-05-01 00:00:00', 'description' => 'LiteTest desc']);
    $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH upd']);
    LiteAssert::same(['2026-05-01 00:00:00', 'LiteTest desc'], [$row($a, $t)['due_at'], $row($a, $t)['description']], 'absent fields kept');
    $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH upd', 'due_at' => '', 'description' => '']);
    LiteAssert::same([null, null], [$row($a, $t)['due_at'], $row($a, $t)['description']], 'cleared');
    $invalid(fn () => $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH upd', 'due_at' => '2026-02-31']), 'due_at', 'bad date on edit');
    foreach (['' => 'empty', '   ' => 'blank'] as $value => $label) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => $value]), 'title', $label);
        $invalid(fn () => $svc($a)->update($a->admin, $t, ['title' => $value]), 'title', "edit {$label}");
    }
    $invalid(fn () => $svc($a)->create($a->admin, ['title' => str_repeat('x', 256)]), 'title', '256 chars');
    LiteAssert::same(255, mb_strlen($svc($a)->create($a->admin, ['title' => str_repeat('ی', 255)])['title']), '255 multibyte chars ok');
    LiteAssert::same('LiteTest TH upd', $row($a, $t)['title']);
};

// ---- Translations, permission, static checks ---------------------------------------
$tests['P0-T every new message key exists (non-empty) in all five languages; no English literal left in TaskController'] = function () {
    $keys = ['tasks.error.title_required', 'tasks.error.title_too_long', 'tasks.error.invalid_assignee', 'tasks.error.invalid_related',
        'tasks.error.invalid_due_date', 'tasks.error.archived', 'tasks.error.invalid_transition', 'tasks.error.generic',
        'tasks.error.choose_file', 'tasks.assignee_inactive'];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        foreach ($keys as $key) {
            LiteAssert::true(isset($strings[$key]) && trim($strings[$key]) !== '', "{$lang}: {$key}");
        }
    }
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/Sales/Controllers/TaskController.php');
    foreach (['Could not', 'Choose a file', "'Invalid"] as $literal) {
        LiteAssert::notContains($literal, $source, $literal);
    }
};

$tests['P0-P tasks.reopen is a registered key, granted to org_admin only by migration 0079; new migrations are additive'] = function () use ($boot) {
    $a = $boot();
    LiteAssert::true(Permission::exists(Permission::TASKS_REOPEN));
    $holders = $a->pdo->query("SELECT r.`key` FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE rp.permission_key = 'tasks.reopen' AND r.is_system = 1")->fetchAll(\PDO::FETCH_COLUMN);
    LiteAssert::same(['org_admin'], array_values(array_unique($holders)));
    $root = dirname(__DIR__, 2);
    foreach (['/modules/Sales/Migrations/0007_add_soft_delete_to_tasks.php', '/database/migrations/0079_grant_tasks_reopen_permission.php'] as $file) {
        $up = explode('public function down', file_get_contents($root . $file))[0];
        LiteAssert::false((bool) preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $up), "{$file}: up() is additive");
    }
    LiteAssert::notContains('deleteTask', file_get_contents($root . '/modules/Sales/TaskRepository.php'), 'no hard-delete path left');
};

// ---- Interim release: task list views ------------------------------------------------
$tests['IR-1 list views: "Created by me" shows delegated tasks; archived and deleted excluded; status filter kept'] = function () use ($boot, $mk, $svc) {
    $a = $boot();
    $a->as($a->admin); // never inherit the previous test's user
    $delegated = $mk($a, 'LiteTest TH IR delegated', (int) $a->admin['id'], [(int) $a->member['id']]);
    $mk($a, 'LiteTest TH IR delegated done', (int) $a->admin['id'], [(int) $a->member['id']], ['status' => 'completed']);
    $mk($a, 'LiteTest TH IR delegated archived', (int) $a->admin['id'], [(int) $a->member['id']], ['archived_at' => date('Y-m-d H:i:s')]);
    $mk($a, 'LiteTest TH IR delegated deleted', (int) $a->admin['id'], [(int) $a->member['id']], ['deleted_at' => date('Y-m-d H:i:s')]);
    $mk($a, 'LiteTest TH IR members own', (int) $a->member['id'], [(int) $a->member['id']]);

    LiteAssert::notContains('LiteTest TH IR delegated<', $a->request('GET', '/tasks')['body'], '"My tasks" = assigned to me (unchanged)');
    // per_page=100: the list is paginated (25 by default) and completed tasks sort last.
    $created = $a->request('GET', '/tasks', ['view' => 'created', 'per_page' => '100']);
    LiteAssert::same(200, $created['status']);
    LiteAssert::contains('LiteTest TH IR delegated<', $created['body'], 'delegated task listed');
    LiteAssert::contains('LiteTest TH IR delegated done', $created['body']);
    LiteAssert::notContains('LiteTest TH IR delegated archived', $created['body'], 'archived excluded');
    LiteAssert::notContains('LiteTest TH IR delegated deleted', $created['body'], 'soft-deleted excluded');
    LiteAssert::notContains('LiteTest TH IR members own', $created['body'], 'only tasks I created');
    LiteAssert::contains('value="/tasks?view=created&amp;per_page=100"', $created['body'], 'actions return to the same view (and page size)');
    $pending = $a->request('GET', '/tasks', ['view' => 'created', 'status' => 'completed'])['body'];
    LiteAssert::contains('LiteTest TH IR delegated done', $pending);
    LiteAssert::notContains('LiteTest TH IR delegated<', $pending, 'status filter applies');
    LiteAssert::same([$delegated], array_values(array_filter(
        array_map(fn ($r) => (int) $r['id'], $svc($a)->listFor($a->admin, 'created', 'pending')),
        fn (int $id) => $id === $delegated
    )), 'service view');
    LiteAssert::same(200, $a->request('GET', '/tasks', ['view' => 'bogus'])['status'], 'unknown view = My tasks');
};

$tests['IR-2 "All tasks": only with organization-wide tasks.view; never wider than the user\'s scope'] = function () use ($boot, $mk, $svc, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin); // never inherit the previous test's user
    $mk($a, 'LiteTest TH IR admin only', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $mk($a, 'LiteTest TH IR admin deleted', (int) $a->admin['id'], [(int) $a->admin['id']], ['deleted_at' => date('Y-m-d H:i:s')]);

    $all = $a->request('GET', '/tasks', ['view' => 'all']);
    LiteAssert::same(200, $all['status']);
    LiteAssert::contains('LiteTest TH IR admin only', $all['body']);
    LiteAssert::notContains('LiteTest TH IR admin deleted', $all['body'], 'soft-deleted excluded');
    $withScopes($a, ['tasks.view' => 'organization'], function () use ($a, $member) {
        $a->as($member($a));
        $page = $a->request('GET', '/tasks', ['view' => 'all'])['body'];
        LiteAssert::contains('LiteTest TH IR admin only', $page, 'organization scope sees all');
        LiteAssert::contains('href="/tasks?view=all"', $a->request('GET', '/tasks')['body'], 'tab offered');
    });
    foreach (['own', 'team', 'department'] as $scope) {
        $withScopes($a, ['tasks.view' => $scope], function () use ($a, $member, $svc, $scope) {
            $a->as($member($a));
            LiteAssert::same('forbidden', $a->outcome('GET', '/tasks', ['view' => 'all']), "{$scope}: All tasks refused");
            LiteAssert::notContains('href="/tasks?view=all"', $a->request('GET', '/tasks')['body'], "{$scope}: tab not offered");
            LiteAssert::false($svc($a)->canListAll($member($a)), $scope);
            LiteAssert::notContains('LiteTest TH IR admin only', $a->request('GET', '/tasks', ['view' => 'created'])['body'], "{$scope}: created view shows only own");
        });
    }
    $withScopes($a, ['tasks.view' => null], function () use ($a, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('GET', '/tasks', ['view' => 'created']), 'no tasks.view');
    });
};

// ---- T1: task card + "New task" dialog + localized dates -------------------------------
/** The HTML of one task's row on a list page ('' when the task is not listed). */
$rowHtml = function (string $page, int $taskId): string {
    $start = strpos($page, 'href="/tasks/' . $taskId . '" class="task-row__title"');
    if ($start === false) {
        return '';
    }
    $end = strpos($page, '</li>', $start);

    return substr($page, $start, $end === false ? null : $end - $start);
};
/** The "New task" dialog's HTML ('' when absent). */
$dialogHtml = function (string $page): string {
    $start = strpos($page, '<dialog class="modal modal--wide" id="task-create-dialog"');

    return $start === false ? '' : substr($page, $start, strpos($page, '</dialog>', $start) - $start);
};

$tests['T1-1 row shows an escaped, whitespace-collapsed, truncated description preview; empty → nothing'] = function () use ($boot, $mk, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $me = [(int) $a->admin['id']];
    $html = $mk($a, 'LiteTest TH T1 html', (int) $a->admin['id'], $me, ['description' => "<script>alert(1)</script> <b>bold</b>\n\n   second   line"]);
    $long = $mk($a, 'LiteTest TH T1 long', (int) $a->admin['id'], $me, ['description' => str_repeat('abcdefghij', 30) . 'TAILMARK']);
    $fa = $mk($a, 'LiteTest TH T1 fa', (int) $a->admin['id'], $me, ['description' => str_repeat('سلام ', 60)]);
    $empty = $mk($a, 'LiteTest TH T1 empty', (int) $a->admin['id'], $me, ['description' => null]);
    $blank = $mk($a, 'LiteTest TH T1 blank', (int) $a->admin['id'], $me, ['description' => " \n\t "]);
    $page = $a->request('GET', '/tasks')['body'];

    $row = $rowHtml($page, $html);
    LiteAssert::contains('class="task-row__desc"', $row);
    LiteAssert::contains('&lt;script&gt;alert(1)&lt;/script&gt; &lt;b&gt;bold&lt;/b&gt; second line', $row, 'escaped + collapsed');
    LiteAssert::notContains('<script>alert(1)', $page, 'never raw HTML');
    LiteAssert::notContains('<b>bold</b>', $row);

    $row = $rowHtml($page, $long);
    LiteAssert::contains(str_repeat('abcdefghij', 14) . '…</p>', $row, 'cut at 140 characters + ellipsis');
    LiteAssert::notContains('TAILMARK', $row, 'rest not shown');

    preg_match('#<p class="task-row__desc">(.*?)</p>#u', $rowHtml($page, $fa), $m);
    $shown = html_entity_decode($m[1] ?? '');
    LiteAssert::true(mb_strlen($shown) <= 141 && mb_strlen($shown) >= 130 && str_ends_with($shown, '…'), 'multibyte: ≤ 140 characters + ellipsis, got ' . mb_strlen($shown));
    LiteAssert::true(str_starts_with(trim(str_repeat('سلام ', 60)), mb_substr($shown, 0, -1)), 'multibyte: a clean prefix (no broken character)');

    foreach ([$empty, $blank] as $id) {
        LiteAssert::true($rowHtml($page, $id) !== '', 'row listed');
        LiteAssert::notContains('task-row__desc', $rowHtml($page, $id), 'no preview element for an empty description');
    }
};

$tests['T1-2 "New task" button + dialog: closed by default, ?new=1 opens it, close/cancel never submit; hidden without tasks.create'] = function () use ($boot, $dialogHtml, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $page = $a->request('GET', '/tasks')['body'];
    LiteAssert::contains('href="/tasks?new=1" data-open-dialog="task-create-dialog"', $page, 'button with the no-JS link');
    $dialog = $dialogHtml($page);
    LiteAssert::true($dialog !== '', 'dialog rendered');
    LiteAssert::notContains('data-autoopen', $dialog, 'closed by default');
    LiteAssert::notContains('class="card card--form"', $page, 'no permanently open create form');
    LiteAssert::contains('formmethod="dialog" formnovalidate', $dialog, 'cancel closes without submitting');
    LiteAssert::contains('<form method="dialog">', $dialog, 'close button');
    LiteAssert::contains('name="return_to" value="/tasks"', $dialog);

    $open = $dialogHtml($a->request('GET', '/tasks', ['new' => '1'])['body']);
    LiteAssert::contains('data-autoopen open', $open, '?new=1 renders it open (no JavaScript needed)');

    $withScopes($a, ['tasks.create' => null], function () use ($a, $member) {
        $a->as($member($a));
        $page = $a->request('GET', '/tasks', ['new' => '1'])['body'];
        LiteAssert::notContains('task-create-dialog', $page, 'no tasks.create: no button, no dialog');
    });
};

$tests['T1-3 failed create from the list: 422, dialog open, message shown, every value kept, nothing written'] = function () use ($boot, $h, $count, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    $member = (string) $a->member['id'];
    $r = $a->request('POST', '/tasks', [], ['title' => '   ', 'description' => 'LiteTest T1 kept description <i>x</i>', 'due_at' => '2026-03-15',
        'priority' => 'high', 'assigned_to_user_ids' => [$member], 'return_to' => '/tasks?view=created&status=pending']);
    LiteAssert::same(422, $r['status'], 'validation status');
    $dialog = $dialogHtml($r['body']);
    LiteAssert::contains('data-autoopen open', $dialog, 'dialog stays open');
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.form_has_errors'), $dialog, 'summary message');
    LiteAssert::contains('<div class="field-error">' . \App\Infrastructure\Theme\t('tasks.error.title_required') . '</div>', $dialog, 'field error under the title');
    LiteAssert::contains('LiteTest T1 kept description &lt;i&gt;x&lt;/i&gt;</textarea>', $dialog, 'description kept (escaped)');
    LiteAssert::contains('name="due_at" value="2026-03-15"', $dialog, 'due date kept');
    LiteAssert::contains('<option value="high" selected>', $dialog, 'priority kept');
    LiteAssert::true((bool) preg_match('#<option value="' . $member . '" selected>#', $dialog), 'assignee kept');
    LiteAssert::false((bool) preg_match('#<option value="' . $a->admin['id'] . '" selected>#', $dialog), 'default assignee not re-added');
    LiteAssert::contains('name="return_to" value="/tasks?view=created&amp;status=pending"', $dialog, 'list view + filter kept');
    LiteAssert::contains('href="/tasks?view=created" aria-current="page"', $r['body'], 'still on the same view');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE description LIKE 'LiteTest T1 kept description%'"), 'nothing written');

    // A field error the form does not show (tampered related record) goes into the summary.
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest T1 tampered', 'related_type' => 'deal', 'related_id' => '999999999', 'return_to' => '/tasks']);
    LiteAssert::same(422, $r['status']);
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.error.invalid_related'), $dialogHtml($r['body']), 'unbound error in the summary');
    LiteAssert::contains('value="LiteTest T1 tampered"', $dialogHtml($r['body']), 'title kept');

    // The deal page's quick add keeps its old behavior (flash + redirect back).
    $r = $a->request('POST', '/tasks', [], ['title' => '', 'related_type' => 'deal', 'related_id' => (string) $h->f->deal, 'return_to' => '/deals/' . $h->f->deal]);
    LiteAssert::same([302, '/deals/' . $h->f->deal], [$r['status'], $r['location']], 'deal quick add unchanged');
};

$tests['T1-4 every list view: dialog returns to the same view + filter; a successful create goes back there'] = function () use ($boot, $dialogHtml, $count) {
    $a = $boot();
    $a->as($a->admin);
    foreach (['mine' => [], 'created' => ['view' => 'created'], 'all' => ['view' => 'all'], 'archived' => ['view' => 'archived'], 'filtered' => ['view' => 'all', 'status' => 'on_hold']] as $label => $query) {
        $expected = '/tasks' . ($query !== [] ? '?' . http_build_query($query) : '');
        $r = $a->request('GET', '/tasks', $query);
        LiteAssert::same(200, $r['status'], $label);
        LiteAssert::contains('name="return_to" value="' . htmlspecialchars($expected, ENT_QUOTES) . '"', $dialogHtml($r['body']), "{$label}: return_to");
        LiteAssert::contains('href="' . htmlspecialchars($expected . (str_contains($expected, '?') ? '&' : '?') . 'new=1', ENT_QUOTES) . '"', $r['body'], "{$label}: no-JS link keeps the view");
        $title = "LiteTest TH T1 back {$label}";
        $post = $a->request('POST', '/tasks', [], ['title' => $title, 'return_to' => $expected]);
        LiteAssert::same([302, $expected], [$post['status'], $post['location']], "{$label}: back to the same view");
        LiteAssert::same(1, $count($a, 'SELECT COUNT(*) FROM tasks WHERE title = ' . $a->pdo->quote($title)), "{$label}: created");
    }
    // An unsafe return_to is still replaced, also on the failure path.
    $r = $a->request('POST', '/tasks', [], ['title' => '', 'return_to' => 'https://evil.example/tasks']);
    LiteAssert::same(422, $r['status'], 'falls back to the task list (422 page), never the external URL');
    LiteAssert::contains('name="return_to" value="/tasks"', $dialogHtml($r['body']));
};

$tests['T1-5 due dates (list + detail) and comment times in the user\'s calendar and digits — fa, en, ar, tr, fr; stored value unchanged'] = function () use ($boot, $mk, $note, $row, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $me = [(int) $a->admin['id']];
    $dateOnly = $mk($a, 'LiteTest TH T1 date', (int) $a->admin['id'], $me, ['due_at' => '2026-03-15 00:00:00']);
    $withTime = $mk($a, 'LiteTest TH T1 time', (int) $a->admin['id'], $me, ['due_at' => '2026-03-15 09:30:00']);
    $n = $note($a, $withTime, (int) $a->admin['id'], 'LiteTest T1 comment');
    $a->pdo->exec("UPDATE notes SET created_at = '2026-03-15 06:00:00' WHERE id = {$n}");
    $commentLocal = (string) \App\Infrastructure\Theme\local_time('2026-03-15 06:00:00');
    try {
        foreach (['fa', 'en', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $fmt = new \App\Infrastructure\Localization\LocaleFormatter($locale);
            $day = $fmt->date('2026-03-15');
            $page = $a->request('GET', '/tasks')['body'];
            LiteAssert::contains('<span class="ltr">' . $day . '</span>', $rowHtml($page, $dateOnly), "{$locale}: date-only shows no time");
            LiteAssert::contains('<span class="ltr">' . $day . ' ' . $fmt->digits('09:30') . '</span>', $rowHtml($page, $withTime), "{$locale}: time shown when set");
            if ($locale !== 'en') {
                LiteAssert::notContains('2026-03-15', $rowHtml($page, $withTime), "{$locale}: no raw ISO date in the row");
            }
            $detail = $a->request('GET', "/tasks/{$withTime}")['body'];
            LiteAssert::contains('<span class="ltr">' . $day . ' ' . $fmt->digits('09:30') . '</span>', $detail, "{$locale}: detail");
            LiteAssert::contains($fmt->date($commentLocal) . ' ' . $fmt->digits(substr($commentLocal, 11, 5)), $detail, "{$locale}: comment time = organization-local");
            LiteAssert::contains('value="2026-03-15"', $detail, "{$locale}: the edit field still carries the ISO date");
        }
        LiteAssert::contains('۱۴۰۴/۱۲/۲۴', (new \App\Infrastructure\Localization\LocaleFormatter('fa'))->date('2026-03-15'), 'fa is Jalali');
    } finally {
        $a->locale('en');
    }
    LiteAssert::same(['2026-03-15 00:00:00', '2026-03-15 09:30:00'], [$row($a, $dateOnly)['due_at'], $row($a, $withTime)['due_at']], 'storage untouched');
};

$tests['T1-6 previews follow the same visibility: other scopes, archived and deleted tasks never leak their description'] = function () use ($boot, $mk, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $mk($a, 'LiteTest TH T1 secret', (int) $a->admin['id'], [(int) $a->admin['id']], ['description' => 'LiteTestSecretPreview']);
    $mk($a, 'LiteTest TH T1 archived', (int) $a->admin['id'], [(int) $a->admin['id']], ['description' => 'LiteTestArchivedPreview', 'archived_at' => date('Y-m-d H:i:s')]);
    $mk($a, 'LiteTest TH T1 deleted', (int) $a->admin['id'], [(int) $a->admin['id']], ['description' => 'LiteTestDeletedPreview', 'deleted_at' => date('Y-m-d H:i:s')]);
    LiteAssert::contains('LiteTestSecretPreview', $a->request('GET', '/tasks', ['view' => 'all'])['body']);
    LiteAssert::notContains('LiteTestArchivedPreview', $a->request('GET', '/tasks', ['view' => 'all'])['body'], 'archived not in working views');
    LiteAssert::contains('LiteTestArchivedPreview', $a->request('GET', '/tasks', ['view' => 'archived'])['body'], 'archived in the archive');
    foreach ([[], ['view' => 'created'], ['view' => 'all'], ['view' => 'archived']] as $query) {
        LiteAssert::notContains('LiteTestDeletedPreview', $a->request('GET', '/tasks', $query)['body'], 'deleted never shown: ' . json_encode($query));
    }
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $member) {
        $a->as($member($a));
        foreach ([[], ['view' => 'created'], ['view' => 'archived']] as $query) {
            LiteAssert::notContains('LiteTestSecretPreview', $a->request('GET', '/tasks', $query)['body'], 'OWN: another\'s description hidden ' . json_encode($query));
        }
    });
};

// ---- T2: start / due date and time ------------------------------------------------------
$tests['T2-1 start/due ordering: before, same day with valid times, equal, all-day mixes allowed; start after due rejected (create + update)'] = function () use ($boot, $svc, $row, $count, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $ok = [
        'dates' => ['start_at' => '2026-04-01', 'due_at' => '2026-04-10'],
        'same day times' => ['start_at' => '2026-04-01', 'start_time' => '09:00', 'due_at' => '2026-04-01', 'due_time' => '17:00'],
        'equal' => ['start_at' => '2026-04-01', 'start_time' => '09:00', 'due_at' => '2026-04-01', 'due_time' => '09:00'],
        'timed start, all-day due' => ['start_at' => '2026-04-01', 'start_time' => '23:00', 'due_at' => '2026-04-01'],
        'all-day start, timed due' => ['start_at' => '2026-04-01', 'due_at' => '2026-04-01', 'due_time' => '08:00'],
        'start only' => ['start_at' => '2026-04-01'],
        'neither' => [],
    ];
    foreach ($ok as $label => $fields) {
        $t = $svc($a)->create($a->admin, ['title' => "LiteTest TH T2 ok {$label}"] + $fields);
        LiteAssert::true((int) $t['id'] > 0, $label);
    }
    foreach (['later date' => ['start_at' => '2026-04-11', 'due_at' => '2026-04-10'],
        'same day later time' => ['start_at' => '2026-04-01', 'start_time' => '17:00', 'due_at' => '2026-04-01', 'due_time' => '09:00']] as $label => $fields) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH T2 bad {$label}"] + $fields), 'start_at', $label);
    }
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH T2 bad%'"), 'nothing written');

    // Update checks against the stored other value.
    $t = $svc($a)->create($a->admin, ['title' => 'LiteTest TH T2 upd', 'start_at' => '2026-04-05', 'due_at' => '2026-04-10']);
    $invalid(fn () => $svc($a)->update($a->admin, (int) $t['id'], ['title' => 'LiteTest TH T2 upd', 'due_at' => '2026-04-04']), 'start_at', 'due moved before stored start');
    LiteAssert::same(['2026-04-05 00:00:00', '2026-04-10 00:00:00'], [$row($a, (int) $t['id'])['start_at'], $row($a, (int) $t['id'])['due_at']], 'unchanged');
    $svc($a)->update($a->admin, (int) $t['id'], ['title' => 'LiteTest TH T2 upd', 'start_at' => '']);
    LiteAssert::same([null, 1], [$row($a, (int) $t['id'])['start_at'], (int) $row($a, (int) $t['id'])['start_all_day']], 'empty start clears it');
};

$tests['T2-2 invalid dates/times are rejected on the right field; nothing written'] = function () use ($boot, $svc, $count, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $cases = [
        'impossible start' => [['start_at' => '2026-02-30'], 'start_at'],
        'bad start format' => [['start_at' => '01/04/2026'], 'start_at'],
        'hour 24' => [['due_at' => '2026-04-01', 'due_time' => '24:00'], 'due_time'],
        'short time' => [['due_at' => '2026-04-01', 'due_time' => '9:5'], 'due_time'],
        'seconds' => [['due_at' => '2026-04-01', 'due_time' => '09:30:00'], 'due_time'],
        'text time' => [['start_at' => '2026-04-01', 'start_time' => 'noon'], 'start_time'],
        'array time' => [['due_at' => '2026-04-01', 'due_time' => ['09:00']], 'due_time'],
        'time, empty date' => [['start_at' => '', 'start_time' => '09:00'], 'start_time'],
        'time, no date key' => [['due_time' => '09:00'], 'due_time'],
        'date with time + time' => [['due_at' => '2026-04-01 10:00', 'due_time' => '09:00'], 'due_at'],
        'array date' => [['start_at' => ['2026-04-01']], 'start_at'],
    ];
    foreach ($cases as $label => [$fields, $field]) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH T2 inv {$label}"] + $fields), $field, $label);
    }
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH T2 inv%'"));
};

$tests['T2-3 empty time = all day; explicit 00:00 = timed midnight; the list shows the difference'] = function () use ($boot, $svc, $row, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $allDay = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T2 allday', 'start_at' => '2026-05-01', 'due_at' => '2026-05-02'])['id'];
    $midnight = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T2 midnight', 'start_at' => '2026-05-01', 'start_time' => '00:00', 'due_at' => '2026-05-02', 'due_time' => '00:00'])['id'];
    $api = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T2 api', 'due_at' => '2026-05-02 09:30'])['id'];
    $pick = fn (int $id) => array_intersect_key($row($a, $id), array_flip(['start_at', 'start_all_day', 'due_at', 'due_all_day']));
    LiteAssert::same(['start_at' => '2026-05-01 00:00:00', 'start_all_day' => 1, 'due_at' => '2026-05-02 00:00:00', 'due_all_day' => 1], array_map(fn ($v) => is_numeric($v) && strlen((string) $v) === 1 ? (int) $v : $v, $pick($allDay)), 'all day');
    LiteAssert::same(['start_at' => '2026-05-01 00:00:00', 'start_all_day' => 0, 'due_at' => '2026-05-02 00:00:00', 'due_all_day' => 0], array_map(fn ($v) => is_numeric($v) && strlen((string) $v) === 1 ? (int) $v : $v, $pick($midnight)), 'midnight');
    LiteAssert::same(['2026-05-02 09:30:00', 0], [$row($a, $api)['due_at'], (int) $row($a, $api)['due_all_day']], 'API date with time stays timed');

    $page = $a->request('GET', '/tasks', ['view' => 'created'])['body'];
    LiteAssert::contains('<span class="ltr">2026-05-02</span>', $rowHtml($page, $allDay), 'all-day: no time');
    LiteAssert::contains('<span class="ltr">2026-05-01</span> · ', $rowHtml($page, $allDay), 'all-day start: no time');
    LiteAssert::contains('<span class="ltr">2026-05-02 00:00</span>', $rowHtml($page, $midnight), 'midnight shown');
    LiteAssert::contains('<span class="ltr">2026-05-01 00:00</span>', $rowHtml($page, $midnight), 'midnight start shown');
    $detail = $a->request('GET', "/tasks/{$midnight}")['body'];
    LiteAssert::contains('name="due_time" value="00:00"', $detail, 'edit form keeps midnight');
    LiteAssert::contains('name="due_time" value=""', $a->request('GET', "/tasks/{$allDay}")['body'], 'edit form: all day = empty time');
};

$tests['T2-4 organization-local wall clock: stored and shown exactly as typed, whatever the organization\'s zone'] = function () use ($boot, $svc, $row, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $tz = \App\Infrastructure\Time\OrganizationTimezone::shared();
    $old = $a->pdo->query("SELECT default_timezone FROM organizations WHERE id = {$a->orgId}")->fetchColumn();
    try {
        foreach (['America/New_York', 'Asia/Tokyo'] as $zone) {
            $a->pdo->prepare('UPDATE organizations SET default_timezone = ? WHERE id = ?')->execute([$zone, $a->orgId]);
            $tz->forget($a->orgId);
            $id = (int) $svc($a)->create($a->admin, ['title' => "LiteTest TH T2 tz {$zone}", 'start_at' => '2026-03-08', 'start_time' => '02:30', 'due_at' => '2026-03-08', 'due_time' => '23:30'])['id'];
            LiteAssert::same(['2026-03-08 02:30:00', '2026-03-08 23:30:00'], [$row($a, $id)['start_at'], $row($a, $id)['due_at']], "{$zone}: stored as typed (no conversion, no DST shift)");
            $html = $rowHtml($a->request('GET', '/tasks', ['view' => 'created'])['body'], $id);
            LiteAssert::contains('2026-03-08 02:30', $html, "{$zone}: start shown as typed");
            LiteAssert::contains('2026-03-08 23:30', $html, "{$zone}: due shown as typed");
        }
    } finally {
        $a->pdo->prepare('UPDATE organizations SET default_timezone = ? WHERE id = ?')->execute([$old, $a->orgId]);
        $tz->forget($a->orgId);
    }
};

$tests['T2-5 existing tasks: shown as before; editing and saving the unchanged form never moves a date or drops a time'] = function () use ($boot, $mk, $row) {
    $a = $boot();
    $a->as($a->admin);
    $me = [(int) $a->admin['id']];
    $dateOnly = $mk($a, 'LiteTest TH T2 legacy date', (int) $a->admin['id'], $me, ['due_at' => '2026-06-01 00:00:00']);
    $timed = $mk($a, 'LiteTest TH T2 legacy timed', (int) $a->admin['id'], $me, ['due_at' => '2026-06-02 14:15:00', 'due_all_day' => 0, 'start_at' => '2026-06-01 08:05:00', 'start_all_day' => 0]);
    foreach ([$dateOnly, $timed] as $id) {
        $before = $row($a, $id);
        $form = $a->request('GET', "/tasks/{$id}")['body'];
        $value = fn (string $name): string => preg_match('#name="' . $name . '" value="([^"]*)"#', $form, $m) ? html_entity_decode($m[1]) : 'MISSING';
        $r = $a->request('POST', "/tasks/{$id}", [], ['title' => $before['title'], 'description' => '', 'priority' => $before['priority'],
            'start_at' => $value('start_at'), 'start_time' => $value('start_time'), 'due_at' => $value('due_at'), 'due_time' => $value('due_time')]);
        LiteAssert::same(302, $r['status'], "#{$id} saved");
        $after = $row($a, $id);
        foreach (['start_at', 'start_all_day', 'due_at', 'due_all_day'] as $col) {
            LiteAssert::same((string) $before[$col], (string) $after[$col], "#{$id} {$col} unchanged");
        }
    }
};

$tests['T2-6 New task dialog: a scheduling error keeps every value and shows the error under its own field'] = function () use ($boot, $count, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T2 dialog', 'start_at' => '2026-07-10', 'start_time' => '18:00',
        'due_at' => '2026-07-10', 'due_time' => '08:00', 'return_to' => '/tasks?view=all']);
    LiteAssert::same(422, $r['status']);
    $dialog = $dialogHtml($r['body']);
    LiteAssert::contains('data-autoopen open', $dialog);
    foreach (['name="title" value="LiteTest TH T2 dialog"', 'name="start_at" value="2026-07-10"', 'name="start_time" value="18:00"', 'name="due_at" value="2026-07-10"', 'name="due_time" value="08:00"'] as $kept) {
        LiteAssert::contains($kept, $dialog, $kept);
    }
    LiteAssert::true((bool) preg_match('#name="start_time"[^>]*>\s*<div class="field-error">|name="start_at"[^>]*>\s*<div class="field-error">' . preg_quote(\App\Infrastructure\Theme\t('tasks.error.start_after_due'), '#') . '#u', $dialog), 'error under the start date');
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T2 dialog 2', 'due_time' => '25:00', 'due_at' => '2026-07-10', 'return_to' => '/tasks']);
    LiteAssert::contains('name="due_time" value="25:00">' . "\n" . '            <div class="field-error">' . \App\Infrastructure\Theme\t('tasks.error.invalid_time') . '</div>', $dialogHtml($r['body']), 'error under the due time');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH T2 dialog%'"), 'nothing written');
};

$tests['T2-7 scheduling edits keep permissions, tenant isolation and the archive lock'] = function () use ($boot, $mk, $row, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T2 perm', (int) $a->admin['id'], [(int) $a->admin['id']], ['due_at' => '2026-08-01 00:00:00']);
    $withScopes($a, ['tasks.edit' => 'own'], function () use ($a, $t, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}", [], ['title' => 'x', 'start_at' => '2026-07-01', 'due_at' => '2026-08-01']), 'not own → 403');
    });
    LiteAssert::same([null, '2026-08-01 00:00:00'], [$row($a, $t)['start_at'], $row($a, $t)['due_at']], 'unchanged');
    if ($a->otherOrgId !== null) {
        $a->pdo->prepare('INSERT INTO tasks (organization_id, title) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest TH T2 xorg']);
        $x = (int) $a->pdo->lastInsertId();
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$x}", [], ['title' => 'x', 'start_at' => '2026-07-01']), 'other organization → 404');
        LiteAssert::same(null, $row($a, $x)['start_at']);
    }
    $a->pdo->exec("UPDATE tasks SET archived_at = NOW() WHERE id = {$t}");
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T2 perm', 'start_at' => '2026-07-01']);
    LiteAssert::same(null, $row($a, $t)['start_at'], 'archived: read-only');
};

$tests['T2-8 deal quick add unchanged: date only → all-day task, back to the deal page; the deal page shows the date'] = function () use ($boot, $h, $row) {
    $a = $boot();
    $a->as($a->admin);
    $deal = $h->f->deal;
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T2 deal quick', 'related_type' => 'deal', 'related_id' => (string) $deal,
        'due_at' => '2026-09-01', 'assigned_to_user_ids' => [(string) $a->admin['id']], 'return_to' => '/deals/' . $deal]);
    LiteAssert::same([302, '/deals/' . $deal], [$r['status'], $r['location']]);
    $id = (int) $a->pdo->query("SELECT id FROM tasks WHERE title = 'LiteTest TH T2 deal quick'")->fetchColumn();
    LiteAssert::same(['2026-09-01 00:00:00', 1, null], [$row($a, $id)['due_at'], (int) $row($a, $id)['due_all_day'], $row($a, $id)['start_at']]);
    LiteAssert::contains('<span class="ltr">2026-09-01</span>', $a->request('GET', '/deals/' . $deal)['body'], 'deal page');
};

$tests['T2-9 start and due in the user\'s calendar and digits — fa, en, ar, tr, fr (list + detail)'] = function () use ($boot, $svc, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $id = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T2 locale', 'start_at' => '2026-03-14', 'start_time' => '07:45', 'due_at' => '2026-03-15'])['id'];
    try {
        foreach (['fa', 'en', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            $fmt = new \App\Infrastructure\Localization\LocaleFormatter($locale);
            $start = $fmt->date('2026-03-14') . ' ' . $fmt->digits('07:45');
            $due = $fmt->date('2026-03-15');
            $html = $rowHtml($a->request('GET', '/tasks', ['view' => 'created'])['body'], $id);
            LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.start') . ' <span class="ltr">' . $start . '</span>', $html, "{$locale}: list start");
            LiteAssert::contains('<span class="ltr">' . $due . '</span>', $html, "{$locale}: list due (all day)");
            $detail = $a->request('GET', "/tasks/{$id}")['body'];
            LiteAssert::contains('<span class="ltr">' . $start . '</span>', $detail, "{$locale}: detail start");
            LiteAssert::contains('<span class="ltr">' . $due . '</span>', $detail, "{$locale}: detail due");
        }
    } finally {
        $a->locale('en');
    }
};

// ---- T3: checklist -----------------------------------------------------------------------
/** A POST through the real router with Accept: application/json (what the page's fetch() sends). */
$jsonPost = function (LocalApp $a, string $path, array $body): array {
    $a->container()->make(\App\Infrastructure\Modules\ModuleGate::class)->reset();
    $_SERVER['REQUEST_URI'] = $path;
    $body['_csrf'] ??= \App\Infrastructure\Security\Csrf::token();
    $response = $a->app->router->dispatch(new \App\Core\Request('POST', $path, [], $body, ['ACCEPT' => 'application/json'], []));
    $read = fn (string $p) => (new \ReflectionProperty(\App\Core\Response::class, $p))->getValue($response);

    return ['status' => $read('status'), 'json' => json_decode((string) $read('body'), true)];
};
$items = fn (LocalApp $a, int $taskId): array => $a->pdo->query("SELECT id, title, is_done, completed_at, completed_by_user_id, sort_order FROM task_checklist_items WHERE task_id = {$taskId} ORDER BY sort_order, id")->fetchAll(\PDO::FETCH_ASSOC);

$tests['T3-1 checklist items: add (in order), rename, complete, uncomplete (idempotent), delete — plain form posts'] = function () use ($boot, $mk, $items) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 crud', (int) $a->admin['id'], [(int) $a->admin['id']]);
    foreach (['First', 'Second', 'Third'] as $title) {
        $r = $a->request('POST', "/tasks/{$t}/checklist", [], ['title' => "  {$title}  "]);
        LiteAssert::same([302, "/tasks/{$t}#checklist"], [$r['status'], $r['location']], "add {$title}");
    }
    $rows = $items($a, $t);
    LiteAssert::same(['First', 'Second', 'Third'], array_column($rows, 'title'), 'trimmed, in order');
    LiteAssert::same([1, 2, 3], array_map('intval', array_column($rows, 'sort_order')), 'stable order');
    [$first, $second, $third] = array_map(fn ($r) => (int) $r['id'], $rows);

    $a->request('POST', "/tasks/{$t}/checklist/{$second}", [], ['title' => 'Second renamed']);
    $a->request('POST', "/tasks/{$t}/checklist/{$first}/toggle", [], ['done' => '1']);
    $a->request('POST', "/tasks/{$t}/checklist/{$first}/toggle", [], ['done' => '1']);
    $rows = $items($a, $t);
    LiteAssert::same('Second renamed', $rows[1]['title']);
    LiteAssert::same([1, (int) $a->admin['id']], [(int) $rows[0]['is_done'], (int) $rows[0]['completed_by_user_id']], 'completed (twice = once)');
    LiteAssert::true($rows[0]['completed_at'] !== null);
    $a->request('POST', "/tasks/{$t}/checklist/{$first}/toggle", [], ['done' => '0']);
    LiteAssert::same([0, null, null], [(int) $items($a, $t)[0]['is_done'], $items($a, $t)[0]['completed_at'], $items($a, $t)[0]['completed_by_user_id']], 'uncompleted');
    $a->request('POST', "/tasks/{$t}/checklist/{$third}/delete");
    LiteAssert::same([$first, $second], array_map(fn ($r) => (int) $r['id'], $items($a, $t)), 'deleted');
};

$tests['T3-2 fetch (JSON): add and tick return the item HTML + progress; a blank title is a 422 with the field error'] = function () use ($boot, $mk, $jsonPost, $items) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 json', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $r = $jsonPost($a, "/tasks/{$t}/checklist", ['title' => '<b>Call</b> client']);
    LiteAssert::same(200, $r['status']);
    $id = (int) $items($a, $t)[0]['id'];
    LiteAssert::contains('data-checklist-item="' . $id . '"', $r['json']['data']['html']);
    LiteAssert::contains('&lt;b&gt;Call&lt;/b&gt; client', $r['json']['data']['html'], 'escaped');
    LiteAssert::same(['done' => 0, 'total' => 1], $r['json']['data']['progress']);
    LiteAssert::same('0/1 completed', $r['json']['data']['progress_label']);

    $r = $jsonPost($a, "/tasks/{$t}/checklist/{$id}/toggle", ['done' => '1']);
    LiteAssert::contains('is-done', $r['json']['data']['html']);
    LiteAssert::contains('name="done" value="0"', $r['json']['data']['html'], 'next click un-ticks');
    LiteAssert::same(['done' => 1, 'total' => 1], $r['json']['data']['progress']);

    $r = $jsonPost($a, "/tasks/{$t}/checklist", ['title' => '   ']);
    LiteAssert::same(422, $r['status']);
    LiteAssert::same(\App\Infrastructure\Theme\t('tasks.error.checklist_title_required'), $r['json']['errors'][0]['title']);
    LiteAssert::true(isset($r['json']['errors'][0]['meta']['fields']['checklist_title']));
    LiteAssert::same(1, count($items($a, $t)), 'nothing written');
};

$tests['T3-3 blank, too long or malformed titles are rejected (service + route); nothing written'] = function () use ($boot, $mk, $svc, $items, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 invalid', (int) $a->admin['id'], [(int) $a->admin['id']]);
    foreach (['empty' => '', 'blank' => " \n\t ", 'too long' => str_repeat('x', 256), 'array' => ['x'], 'null' => null] as $label => $title) {
        $invalid(fn () => $svc($a)->addChecklistItem($a->admin, $t, $title), 'checklist_title', $label);
    }
    $r = $a->request('POST', "/tasks/{$t}/checklist", [], ['title' => '']);
    LiteAssert::same([302, "/tasks/{$t}#checklist"], [$r['status'], $r['location']], 'plain post: back with a flash');
    LiteAssert::same([], $items($a, $t));
    $ok = $svc($a)->addChecklistItem($a->admin, $t, str_repeat('ی', 255));
    $invalid(fn () => $svc($a)->renameChecklistItem($a->admin, $t, (int) $ok['id'], '  '), 'checklist_title', 'rename to blank');
    LiteAssert::same(str_repeat('ی', 255), $items($a, $t)[0]['title'], 'unchanged');
};

$tests['T3-4 progress: task page count + bar + items, list row "done/total" + bar; none without items'] = function () use ($boot, $mk, $svc, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 progress', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $empty = $mk($a, 'LiteTest TH T3 no items', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $ids = [];
    foreach (['A', 'B', 'C'] as $title) {
        $ids[] = (int) $svc($a)->addChecklistItem($a->admin, $t, "LiteTest item {$title}")['id'];
    }
    $svc($a)->setChecklistItemDone($a->admin, $t, $ids[1], true);

    $page = $a->request('GET', "/tasks/{$t}")['body'];
    LiteAssert::contains('data-checklist-count>1/3 completed</span>', $page);
    LiteAssert::contains('max="3" value="1" aria-label="Checklist" data-checklist-progress>', $page);
    LiteAssert::true(strpos($page, 'LiteTest item A') < strpos($page, 'LiteTest item B') && strpos($page, 'LiteTest item B') < strpos($page, 'LiteTest item C'), 'in order');
    LiteAssert::contains('data-checklist-empty hidden', $page);
    LiteAssert::notContains('data-checklist-empty hidden', $a->request('GET', "/tasks/{$empty}")['body'], 'empty message on an empty checklist');

    $list = $a->request('GET', '/tasks')['body'];
    LiteAssert::contains('1/3 completed', $rowHtml($list, $t));
    LiteAssert::contains('class="progress task-row__progress" max="3" value="1"', $rowHtml($list, $t));
    LiteAssert::notContains('task-row__progress', $rowHtml($list, $empty), 'no indicator without items');

    foreach ($ids as $id) {
        $svc($a)->setChecklistItemDone($a->admin, $t, $id, true);
    }
    LiteAssert::contains('progress--done', $rowHtml($a->request('GET', '/tasks')['body'], $t), 'all done');
    $a->locale('fa');
    try {
        LiteAssert::contains('۳ از ۳ انجام شده', $rowHtml($a->request('GET', '/tasks')['body'], $t), 'fa digits');
    } finally {
        $a->locale('en');
    }
};

$tests['T3-5 editing the task (title, dates, assignees, status) keeps its checklist exactly'] = function () use ($boot, $mk, $svc, $items) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 keep', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $first = $svc($a)->addChecklistItem($a->admin, $t, 'Keep 1');
    $svc($a)->addChecklistItem($a->admin, $t, 'Keep 2');
    $svc($a)->setChecklistItemDone($a->admin, $t, (int) $first['id'], true);
    $before = $items($a, $t);
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T3 keep renamed', 'description' => 'd', 'start_at' => '2026-10-01', 'start_time' => '', 'due_at' => '2026-10-05', 'due_time' => '10:00',
        'priority' => 'high', 'assigned_to_user_ids' => [(string) $a->admin['id'], (string) $a->member['id']]]);
    $svc($a)->changeStatus($a->admin, $t, 'in_progress');
    LiteAssert::same('LiteTest TH T3 keep renamed', $a->pdo->query("SELECT title FROM tasks WHERE id = {$t}")->fetchColumn(), 'task edited');
    LiteAssert::same($before, $items($a, $t), 'checklist unchanged');
};

$tests['T3-6 permissions: tasks.edit on the task to change; viewers see a read-only list; other task / organization / deleted / archived refused'] = function () use ($boot, $h, $mk, $svc, $items, $withScopes, $member, $jsonPost) {
    $a = $boot();
    $a->as($a->admin);
    $adminTask = $mk($a, 'LiteTest TH T3 admin task', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $memberTask = $mk($a, 'LiteTest TH T3 member task', (int) $a->admin['id'], [(int) $a->member['id']]);
    $item = (int) $svc($a)->addChecklistItem($a->admin, $adminTask, 'LiteTest guarded item')['id'];
    $before = $items($a, $adminTask);

    $withScopes($a, ['tasks.edit' => 'own', 'tasks.view' => 'organization'], function () use ($a, $adminTask, $memberTask, $item, $member, $items, $jsonPost) {
        $a->as($member($a));
        foreach (["/tasks/{$adminTask}/checklist" => ['title' => 'x'], "/tasks/{$adminTask}/checklist/{$item}" => ['title' => 'x'],
            "/tasks/{$adminTask}/checklist/{$item}/toggle" => ['done' => '1'], "/tasks/{$adminTask}/checklist/{$item}/delete" => []] as $path => $body) {
            LiteAssert::same('forbidden', $a->outcome('POST', $path, [], $body), "not own: {$path}");
        }
        $page = $a->request('GET', "/tasks/{$adminTask}")['body'];
        LiteAssert::contains('LiteTest guarded item', $page, 'viewer sees the items');
        LiteAssert::notContains('data-checklist-add', $page, 'no add form');
        LiteAssert::notContains('/checklist/' . $item . '/toggle', $page, 'no toggle form');
        LiteAssert::contains('aria-disabled="true"', $page, 'read-only checkbox');
        LiteAssert::same('ok', $a->outcome('POST', "/tasks/{$memberTask}/checklist", [], ['title' => 'LiteTest assignee item']), 'assignee (own) may add');
        LiteAssert::same(1, count($items($a, $memberTask)));
    });
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $adminTask, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('GET', "/tasks/{$adminTask}"), 'no view: page refused, items not shown');
    });
    LiteAssert::same($before, $items($a, $adminTask), 'nothing changed');

    // The item must belong to the task in the URL; other organizations and deleted tasks are 404.
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$memberTask}/checklist/{$item}/toggle", [], ['done' => '1']), 'item via another task');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$memberTask}/checklist/{$item}/delete"), 'delete via another task');
    if ($a->otherOrgId !== null) {
        $a->pdo->prepare('INSERT INTO tasks (organization_id, title) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest TH T3 xorg']);
        $x = (int) $a->pdo->lastInsertId();
        $a->pdo->prepare('INSERT INTO task_checklist_items (organization_id, task_id, title) VALUES (?, ?, ?)')->execute([$a->otherOrgId, $x, 'LiteTestXorgItem']);
        $xItem = (int) $a->pdo->lastInsertId();
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$x}/checklist", [], ['title' => 'x']), 'other organization task');
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$adminTask}/checklist/{$xItem}/toggle", [], ['done' => '1']), 'other organization item via my task');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT is_done FROM task_checklist_items WHERE id = {$xItem}")->fetchColumn());
    }
    $a->pdo->exec("UPDATE tasks SET deleted_at = NOW() WHERE id = {$memberTask}");
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$memberTask}/checklist", [], ['title' => 'x']), 'deleted task');
    // JSON callers get the same 404 (the ErrorHandler renders it as JSON in the app).
    LiteAssert::throws(NotFoundException::class, fn () => $jsonPost($a, "/tasks/{$memberTask}/checklist", ['title' => 'x']), 'deleted task (JSON)');
    $a->pdo->exec("UPDATE tasks SET archived_at = NOW() WHERE id = {$adminTask}");
    $r = $a->request('POST', "/tasks/{$adminTask}/checklist/{$item}/toggle", [], ['done' => '1']);
    LiteAssert::same(302, $r['status'], 'archived: refused with a message');
    LiteAssert::same($before, $items($a, $adminTask), 'archived: read-only');
};

$tests['T3-7 CSRF: every checklist route refuses a missing or wrong token; nothing written'] = function () use ($boot, $mk, $svc, $items) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T3 csrf', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $item = (int) $svc($a)->addChecklistItem($a->admin, $t, 'LiteTest csrf item')['id'];
    $before = $items($a, $t);
    foreach (["/tasks/{$t}/checklist" => ['title' => 'x'], "/tasks/{$t}/checklist/{$item}" => ['title' => 'x'],
        "/tasks/{$t}/checklist/{$item}/toggle" => ['done' => '1'], "/tasks/{$t}/checklist/{$item}/delete" => []] as $path => $body) {
        LiteAssert::same('csrf', $a->outcome('POST', $path, [], $body + ['_csrf' => 'wrong']), "wrong token: {$path}");
        LiteAssert::same('csrf', $a->outcome('POST', $path, [], $body + ['_csrf' => '']), "missing token: {$path}");
    }
    LiteAssert::same($before, $items($a, $t));
};

// ---- T4: task labels -------------------------------------------------------------------------
$labelSvc = fn (LocalApp $a): \Modules\Sales\Services\TaskLabelService => new \Modules\Sales\Services\TaskLabelService(
    new \Modules\Sales\TaskLabelRepository(),
    $a->container()->make(\App\Infrastructure\Authorization\AuthorizationEngine::class)
);
$labelRow = fn (LocalApp $a, string $name) => $a->pdo->query('SELECT * FROM task_labels WHERE organization_id = ' . $a->orgId . ' AND name = ' . $a->pdo->quote($name))->fetch(\PDO::FETCH_ASSOC);
$linkIds = fn (LocalApp $a, int $taskId): array => array_map('intval', $a->pdo->query("SELECT label_id FROM task_label_links WHERE task_id = {$taskId} ORDER BY label_id")->fetchAll(\PDO::FETCH_COLUMN));

$tests['T4-1 labels: create, rename, recolor; blank / too long / duplicate names and unknown colors rejected; names escaped'] = function () use ($boot, $labelSvc, $labelRow, $invalid, $count) {
    $a = $boot();
    $a->as($a->admin);
    $r = $a->request('POST', '/tasks/labels', [], ['name' => '  LiteTest   Urgent ', 'color' => 'red']);
    LiteAssert::same([302, '/tasks/labels'], [$r['status'], $r['location']]);
    $label = $labelRow($a, 'LiteTest Urgent');
    LiteAssert::true($label !== false && $label['color'] === 'red', 'created (whitespace collapsed)');
    $a->request('POST', '/tasks/labels/' . $label['id'], [], ['name' => 'LiteTest Hot', 'color' => 'orange']);
    $renamed = $labelRow($a, 'LiteTest Hot');
    LiteAssert::same([(int) $label['id'], 'orange'], [(int) $renamed['id'], $renamed['color']], 'renamed + recolored');

    foreach (['blank' => ['name' => '  ', 'color' => 'red'], 'too long' => ['name' => str_repeat('x', 51), 'color' => 'red'], 'duplicate' => ['name' => 'litetest hot', 'color' => 'red']] as $case => $input) {
        $invalid(fn () => $labelSvc($a)->create($a->admin, $input), 'label_name', $case);
    }
    foreach (['purple', 'red;background:url(x)', '<b>red</b>', '', null, ['red']] as $color) {
        $invalid(fn () => $labelSvc($a)->create($a->admin, ['name' => 'LiteTest color', 'color' => $color]), 'label_color', json_encode($color));
    }
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM task_labels WHERE name = 'LiteTest color'"), 'nothing written');

    $labelSvc($a)->create($a->admin, ['name' => 'LiteTest <script>x</script>', 'color' => 'blue']);
    $page = $a->request('GET', '/tasks/labels')['body'];
    LiteAssert::contains('LiteTest &lt;script&gt;x&lt;/script&gt;', $page, 'escaped');
    LiteAssert::notContains('<script>x</script>', $page);
    LiteAssert::contains('task-label task-label--orange', $page, 'palette class only');
};

$tests['T4-2 assign and remove several labels on a task; list row and task page show the pills'] = function () use ($boot, $mk, $labelSvc, $linkIds, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T4 assign', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $x = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest L1', 'color' => 'green'])['id'];
    $y = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest L2', 'color' => 'sky'])['id'];
    $r = $a->request('POST', "/tasks/{$t}/labels", [], ['label_ids' => [(string) $x, (string) $y]]);
    LiteAssert::same([302, "/tasks/{$t}#labels"], [$r['status'], $r['location']]);
    $both = [$x, $y];
    sort($both);
    LiteAssert::same($both, $linkIds($a, $t));
    $row = $rowHtml($a->request('GET', '/tasks')['body'], $t);
    LiteAssert::contains('<span class="task-label task-label--green">LiteTest L1</span>', $row);
    LiteAssert::contains('<span class="task-label task-label--sky">LiteTest L2</span>', $row);
    LiteAssert::contains('value="' . $x . '" checked', $a->request('GET', "/tasks/{$t}")['body'], 'task page: checked');
    $a->request('POST', "/tasks/{$t}/labels", [], ['label_ids' => [(string) $y]]);
    LiteAssert::same([$y], $linkIds($a, $t), 'one removed');
    $a->request('POST', "/tasks/{$t}/labels", [], []);
    LiteAssert::same([], $linkIds($a, $t), 'none checked = all removed');
};

$tests['T4-3 other organization\'s labels never assignable; members cannot manage labels or label others\' tasks; archived / deleted refused'] = function () use ($boot, $mk, $svc, $labelSvc, $linkIds, $invalid, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T4 guard', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $mine = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest guard', 'color' => 'gray'])['id'];
    if ($a->otherOrgId !== null) {
        $a->pdo->prepare('INSERT INTO task_labels (organization_id, name, color) VALUES (?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest xorg label', 'red']);
        $foreign = (int) $a->pdo->lastInsertId();
        $invalid(fn () => $svc($a)->setLabels($a->admin, $t, [(string) $mine, (string) $foreign]), 'labels', 'foreign label');
        LiteAssert::same([], $linkIds($a, $t), 'nothing assigned (not even the valid one)');
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/labels/{$foreign}", [], ['name' => 'x', 'color' => 'red']), 'cannot rename another organization\'s label');
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/labels/{$foreign}/delete"), 'cannot delete it');
    }
    $invalid(fn () => $svc($a)->setLabels($a->admin, $t, ['999999999']), 'labels', 'unknown label');
    $withScopes($a, ['tasks.edit' => 'own'], function () use ($a, $t, $mine, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('GET', '/tasks/labels'), 'member: no management page');
        LiteAssert::same('forbidden', $a->outcome('POST', '/tasks/labels', [], ['name' => 'LiteTest member label', 'color' => 'red']));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/labels/{$mine}/delete"));
        LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$t}/labels", [], ['label_ids' => [(string) $mine]]), 'not own task');
        LiteAssert::notContains('href="/tasks/labels"', $a->request('GET', '/tasks')['body'], 'no manage button');
    });
    LiteAssert::same([], $linkIds($a, $t));
    $a->pdo->exec("UPDATE tasks SET archived_at = NOW() WHERE id = {$t}");
    $invalid(fn () => $svc($a)->setLabels($a->admin, $t, [(string) $mine]), 'task', 'archived');
    $a->pdo->exec("UPDATE tasks SET archived_at = NULL, deleted_at = NOW() WHERE id = {$t}");
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$t}/labels", [], ['label_ids' => [(string) $mine]]), 'deleted task');
};

$tests['T4-4 deleting a label removes it from tasks only — tasks and contact tags untouched'] = function () use ($boot, $h, $mk, $svc, $labelSvc, $linkIds, $count) {
    $a = $boot();
    $a->as($a->admin);
    $t1 = $mk($a, 'LiteTest TH T4 del 1', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $t2 = $mk($a, 'LiteTest TH T4 del 2', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $gone = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest gone', 'color' => 'red'])['id'];
    $stays = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest stays', 'color' => 'blue'])['id'];
    $svc($a)->setLabels($a->admin, $t1, [$gone, $stays]);
    $svc($a)->setLabels($a->admin, $t2, [$gone]);
    $a->pdo->prepare('INSERT INTO tags (organization_id, name, color) VALUES (?, ?, ?)')->execute([$a->orgId, 'LiteTest gone', 'red']);
    $tag = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO taggables (tag_id, taggable_type, taggable_id) VALUES (?, 'contact', ?)")->execute([$tag, $h->f->contact]);
    $tagsBefore = [$count($a, 'SELECT COUNT(*) FROM tags'), $count($a, 'SELECT COUNT(*) FROM taggables')];

    $r = $a->request('POST', "/tasks/labels/{$gone}/delete");
    LiteAssert::same(302, $r['status']);
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM task_labels WHERE id = {$gone}"), 'label deleted');
    LiteAssert::same([$stays], $linkIds($a, $t1), 'other label kept');
    LiteAssert::same([], $linkIds($a, $t2));
    LiteAssert::same(2, $count($a, "SELECT COUNT(*) FROM tasks WHERE id IN ({$t1}, {$t2}) AND deleted_at IS NULL"), 'tasks kept');
    LiteAssert::same($tagsBefore, [$count($a, 'SELECT COUNT(*) FROM tags'), $count($a, 'SELECT COUNT(*) FROM taggables')], 'contact tags untouched');
};

$tests['T4-5 CSRF on every label route'] = function () use ($boot, $mk, $labelSvc, $count) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TH T4 csrf', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $id = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest csrf', 'color' => 'red'])['id'];
    foreach (['/tasks/labels' => ['name' => 'LiteTest csrf new', 'color' => 'red'], "/tasks/labels/{$id}" => ['name' => 'x', 'color' => 'red'], "/tasks/labels/{$id}/delete" => [], "/tasks/{$t}/labels" => ['label_ids' => [(string) $id]]] as $path => $body) {
        LiteAssert::same('csrf', $a->outcome('POST', $path, [], $body + ['_csrf' => 'wrong']), $path);
    }
    LiteAssert::same([1, 0, 0], [$count($a, "SELECT COUNT(*) FROM task_labels WHERE id = {$id} AND name = 'LiteTest csrf'"), $count($a, "SELECT COUNT(*) FROM task_labels WHERE name = 'LiteTest csrf new'"), $count($a, "SELECT COUNT(*) FROM task_label_links WHERE task_id = {$t}")]);
};

// ---- T5: related project / contract / deal --------------------------------------------------
/** Projects + Contracts entitled (in the transaction) and one record of each, owned by the admin. */
$t5 = function (LocalApp $a) use ($h): \stdClass {
    if (!$a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class)->has('project')
        || !$a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class)->has('contract')) {
        throw new LiteSkip('Projects / Contracts not installed');
    }
    if (!isset($h->t5)) {
        ProjectsLite::activate($a, 'projects', 'Projects');
        $a->activateContracts();
        $x = new \stdClass();
        $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'LiteTestSecretProject', 'active', ?, ?)")
            ->execute([$a->orgId, 'LT-T5-' . bin2hex(random_bytes(3)), (int) $a->admin['id'], (int) $a->admin['id']]);
        $x->project = (int) $a->pdo->lastInsertId();
        $a->pdo->prepare("INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, 'LiteTestSecretContract', ?, ?)")
            ->execute([$a->orgId, (int) $a->admin['id'], (int) $a->admin['id']]);
        $x->contract = (int) $a->pdo->lastInsertId();
        $x->deal = $h->f->deal;
        $x->foreignProject = ProjectsLite::foreignProject($a);
        $h->t5 = $x;
    }

    return $h->t5;
};

$tests['T5-1 link a task to a project, a contract or a deal from the dialog; list and task page link to it'] = function () use ($boot, $t5, $row, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $x = $t5($a);
    $dialog = $a->request('GET', '/tasks')['body'];
    foreach (['project' => $x->project, 'contract' => $x->contract, 'deal' => $x->deal] as $type => $id) {
        LiteAssert::contains('<option value="' . $type . ':' . $id . '"', $dialog, "{$type} offered");
        $title = "LiteTest TH T5 link {$type}";
        $r = $a->request('POST', '/tasks', [], ['title' => $title, 'related' => "{$type}:{$id}", 'return_to' => '/tasks']);
        LiteAssert::same(302, $r['status'], $type);
        $task = (int) $a->pdo->query('SELECT id FROM tasks WHERE title = ' . $a->pdo->quote($title))->fetchColumn();
        LiteAssert::same([$type, $id], [$row($a, $task)['related_type'], (int) $row($a, $task)['related_id']]);
        $url = ['project' => "/projects/{$id}", 'contract' => "/contracts/{$id}", 'deal' => "/deals/{$id}"][$type];
        LiteAssert::contains('<a href="' . $url . '">', $rowHtml($a->request('GET', '/tasks', ['view' => 'created'])['body'], $task), "{$type}: list link");
        LiteAssert::contains('<a href="' . $url . '">', $a->request('GET', "/tasks/{$task}")['body'], "{$type}: task page link");
    }
};

$tests['T5-2 invalid, unknown, deleted or another organization\'s records are rejected; nothing written'] = function () use ($boot, $t5, $svc, $count, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $x = $t5($a);
    $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'LiteTest gone project', 'draft', ?, ?)")
        ->execute([$a->orgId, 'LT-T5-G' . bin2hex(random_bytes(2)), (int) $a->admin['id'], (int) $a->admin['id']]);
    $gone = (int) $a->pdo->lastInsertId();
    $a->pdo->exec("DELETE FROM projects WHERE id = {$gone}");
    $bad = ['unknown project' => 'project:999999999', 'zero' => 'contract:0', 'text id' => 'project:abc', 'unknown type' => 'widget:1',
        'no id' => 'deal:', 'no colon' => 'deal', 'deleted project' => "project:{$gone}", 'array' => ['deal:' . $x->deal]];
    if ($x->foreignProject !== null) {
        $bad['foreign project'] = 'project:' . $x->foreignProject;
    }
    foreach ($bad as $label => $value) {
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => "LiteTest TH T5 bad {$label}", 'related' => $value]), 'related', $label);
    }
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH T5 bad%'"));
};

$tests['T5-3 record-level access: projects by permission AND membership, contracts and deals by scope; nothing leaks'] = function () use ($boot, $t5, $mk, $svc, $row, $rowHtml, $dialogHtml, $withScopes, $member, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $x = $t5($a);
    $mid = (int) $a->member['id'];
    $contractTask = $mk($a, 'LiteTest TH T5 contract task', (int) $a->admin['id'], [$mid], ['related_type' => 'contract', 'related_id' => $x->contract]);
    $dealTask = $mk($a, 'LiteTest TH T5 deal task', (int) $a->admin['id'], [$mid], ['related_type' => 'deal', 'related_id' => $x->deal]);

    $withScopes($a, ['projects.view' => 'own', 'contracts.view' => 'own', 'deals.view' => 'own'], function () use ($a, $x, $mid, $svc, $contractTask, $dealTask, $rowHtml, $dialogHtml, $member, $invalid) {
        $a->as($member($a));
        $page = $a->request('GET', '/tasks')['body'];
        $dialog = $dialogHtml($page);
        foreach (['project:' . $x->project, 'contract:' . $x->contract, 'deal:' . $x->deal] as $value) {
            LiteAssert::notContains('value="' . $value . '"', $dialog, "{$value} not offered");
        }
        LiteAssert::notContains('LiteTestSecretProject', $page);
        LiteAssert::notContains('LiteTestSecretContract', $page);
        $invalid(fn () => $svc($a)->create($member($a), ['title' => 'LiteTest TH T5 sneaky', 'related' => 'project:' . $x->project]), 'related', 'project not visible');
        $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T5 sneaky route', 'related' => 'contract:' . $x->contract, 'return_to' => '/tasks']);
        LiteAssert::same(422, $r['status']);
        LiteAssert::notContains('LiteTestSecretContract', $r['body'], 'error reveals nothing');

        // A contract task needs the contract: hidden from the assignee without access.
        LiteAssert::false(in_array($contractTask, array_map(fn ($t) => (int) $t['id'], $svc($a)->listFor($member($a), 'mine')), true), 'contract task hidden');
        LiteAssert::same('forbidden', $a->outcome('GET', "/tasks/{$contractTask}"));
        // A deal task stays visible, but the deal is shown as restricted only.
        $html = $rowHtml($a->request('GET', '/tasks')['body'], $dealTask);
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.related_restricted'), $html);
        LiteAssert::notContains('LiteTest TH deal', $html, 'deal name not shown');
        LiteAssert::notContains('/deals/' . $x->deal, $html, 'no link');

        // Project membership counts (ProjectService::canView), not just ownership.
        $a->pdo->prepare('INSERT INTO project_members (organization_id, project_id, user_id) VALUES (?, ?, ?)')->execute([$a->orgId, $x->project, $mid]);
        $ok = $svc($a)->create($member($a), ['title' => 'LiteTest TH T5 member project', 'related' => 'project:' . $x->project]);
        LiteAssert::same('project', $ok['related_type'], 'member of the project may link it');
        LiteAssert::contains('value="project:' . $x->project . '"', $dialogHtml($a->request('GET', '/tasks')['body']), 'offered to a member');
    });
};

$tests['T5-4 a related record that no longer exists: task stays visible, shown as deleted, no stale name'] = function () use ($boot, $t5, $mk, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $t5($a);
    $a->pdo->prepare("INSERT INTO projects (organization_id, code, name, status, owner_id, created_by_user_id) VALUES (?, ?, 'LiteTestVanishedProject', 'draft', ?, ?)")
        ->execute([$a->orgId, 'LT-T5-V' . bin2hex(random_bytes(2)), (int) $a->admin['id'], (int) $a->admin['id']]);
    $p = (int) $a->pdo->lastInsertId();
    $t = $mk($a, 'LiteTest TH T5 orphan', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'project', 'related_id' => $p]);
    $a->pdo->exec("DELETE FROM projects WHERE id = {$p}");
    $html = $rowHtml($a->request('GET', '/tasks')['body'], $t);
    LiteAssert::true($html !== '', 'still listed');
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.related_deleted'), $html);
    LiteAssert::notContains('LiteTestVanishedProject', $html);
    LiteAssert::same(200, $a->request('GET', "/tasks/{$t}")['status']);
};

$tests['T5-5 editing other fields never replaces the relation; "keep" for unlisted relations; change and clear work'] = function () use ($boot, $h, $t5, $mk, $row) {
    $a = $boot();
    $a->as($a->admin);
    $x = $t5($a);
    $t = $mk($a, 'LiteTest TH T5 keep', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'contact', 'related_id' => $h->f->contact]);
    $form = $a->request('GET', "/tasks/{$t}")['body'];
    LiteAssert::contains('<option value="keep" selected>', $form, 'contact relation kept by default');
    $rel = fn () => [$row($a, $t)['related_type'], (int) $row($a, $t)['related_id']];
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T5 keep 2']);
    LiteAssert::same(['contact', $h->f->contact], $rel(), 'no related key: unchanged');
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T5 keep 3', 'related' => 'keep', 'related_type' => 'deal', 'related_id' => (string) $x->deal]);
    LiteAssert::same(['contact', $h->f->contact], $rel(), '"keep" (old fields ignored): unchanged');
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T5 keep 4', 'related' => 'project:' . $x->project]);
    LiteAssert::same(['project', $x->project], $rel(), 'changed');
    LiteAssert::contains('<option value="project:' . $x->project . '" selected>', $a->request('GET', "/tasks/{$t}")['body'], 'listed relation selected');
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T5 keep 5', 'related' => 'project:999999999']);
    LiteAssert::same(['project', $x->project], $rel(), 'invalid change refused, relation kept');
    $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T5 keep 6', 'related' => '']);
    LiteAssert::same([null, 0], $rel(), 'cleared');
};

$tests['T5-6 deal quick add keeps its form and behavior; the deal page links the deal'] = function () use ($boot, $h, $row) {
    $a = $boot();
    $a->as($a->admin);
    $deal = $h->f->deal;
    $page = $a->request('GET', "/deals/{$deal}")['body'];
    LiteAssert::contains('name="related_type" value="deal"', $page, 'quick add form unchanged');
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T5 quick', 'related_type' => 'deal', 'related_id' => (string) $deal, 'return_to' => '/deals/' . $deal]);
    LiteAssert::same([302, '/deals/' . $deal], [$r['status'], $r['location']]);
    $id = (int) $a->pdo->query("SELECT id FROM tasks WHERE title = 'LiteTest TH T5 quick'")->fetchColumn();
    LiteAssert::same(['deal', $deal], [$row($a, $id)['related_type'], (int) $row($a, $id)['related_id']]);
    LiteAssert::contains('<a href="/deals/' . $deal . '">LiteTest TH deal</a>', $a->request('GET', "/deals/{$deal}")['body']);
};

// ---- Search, pagination, labels beside the title ----------------------------------------
/** Ids of the tasks listed on a list page, in order. */
$listedIds = function (string $page): array {
    preg_match_all('#href="/tasks/(\d+)" class="task-row__title"#', $page, $m);

    return array_map('intval', $m[1]);
};

$tests['TP-1 search: title, description and label name; case-insensitive; % and _ are literal; archived / deleted never match'] = function () use ($boot, $mk, $svc, $labelSvc, $listedIds) {
    $a = $boot();
    $a->as($a->admin);
    $me = [(int) $a->admin['id']];
    $byTitle = $mk($a, 'LiteTest TP Zebra title', (int) $a->admin['id'], $me);
    $byDesc = $mk($a, 'LiteTest TP plain', (int) $a->admin['id'], $me, ['description' => 'mentions the zebra crossing']);
    $byLabel = $mk($a, 'LiteTest TP labelled', (int) $a->admin['id'], $me);
    $svc($a)->setLabels($a->admin, $byLabel, [(int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest ZEBRAlabel', 'color' => 'green'])['id']]);
    $other = $mk($a, 'LiteTest TP unrelated', (int) $a->admin['id'], $me);
    $archived = $mk($a, 'LiteTest TP zebra archived', (int) $a->admin['id'], $me, ['archived_at' => date('Y-m-d H:i:s')]);
    $deleted = $mk($a, 'LiteTest TP zebra deleted', (int) $a->admin['id'], $me, ['deleted_at' => date('Y-m-d H:i:s')]);
    $percent = $mk($a, 'LiteTest TP 100% done', (int) $a->admin['id'], $me);

    $ids = $listedIds($a->request('GET', '/tasks', ['view' => 'all', 'q' => 'zEbRa'])['body']);
    sort($ids);
    $expected = [$byTitle, $byDesc, $byLabel];
    sort($expected);
    LiteAssert::same($expected, $ids, 'title + description + label; not archived / deleted / unrelated');
    LiteAssert::same([$archived], $listedIds($a->request('GET', '/tasks', ['view' => 'archived', 'q' => 'zebra'])['body']), 'archive view searches the archive');
    LiteAssert::same([$percent], $listedIds($a->request('GET', '/tasks', ['view' => 'all', 'q' => '100%'])['body']), '% is literal');
    LiteAssert::same([], $listedIds($a->request('GET', '/tasks', ['view' => 'all', 'q' => 'LiteTest_TP'])['body']), '_ is literal');
    $page = $a->request('GET', '/tasks', ['view' => 'all', 'q' => 'nothing-matches-LiteTest'])['body'];
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.search_no_results'), $page);
    LiteAssert::contains('name="q" value="nothing-matches-LiteTest"', $page, 'term kept in the box');
    LiteAssert::contains('value="&lt;b&gt;x&lt;/b&gt;"', $a->request('GET', '/tasks', ['q' => '<b>x</b>'])['body'], 'term escaped');
    LiteAssert::false(in_array($other, $ids, true));
};

$tests['TP-2 pagination: server-side pages of 25 in the list order; links keep view, status and search; per_page works'] = function () use ($boot, $mk, $listedIds) {
    $a = $boot();
    $a->as($a->admin);
    $me = [(int) $a->admin['id']];
    $ids = [];
    for ($i = 1; $i <= 30; $i++) {
        $ids[] = $mk($a, sprintf('LiteTest TP page %02d', $i), (int) $a->admin['id'], $me, ['due_at' => sprintf('2027-01-%02d 00:00:00', $i)]);
    }
    $query = ['view' => 'created', 'status' => 'pending', 'q' => 'LiteTest TP page'];
    $first = $a->request('GET', '/tasks', $query)['body'];
    LiteAssert::same(array_slice($ids, 0, 25), $listedIds($first), 'page 1: first 25 by due date');
    LiteAssert::contains(e_summary(1, 25, 30), $first, 'summary');
    LiteAssert::contains('href="?view=created&amp;status=pending&amp;q=LiteTest+TP+page&amp;page=2"', $first, 'next page link keeps every filter');
    $second = $a->request('GET', '/tasks', $query + ['page' => '2'])['body'];
    LiteAssert::same(array_slice($ids, 25), $listedIds($second), 'page 2: the remaining 5');
    LiteAssert::contains('name="return_to" value="/tasks?view=created&amp;status=pending&amp;q=LiteTest+TP+page&amp;page=2"', $second, 'actions return to page 2');
    LiteAssert::same($ids, $listedIds($a->request('GET', '/tasks', $query + ['per_page' => '50'])['body']), 'per_page=50: all 30');
    LiteAssert::same(array_slice($ids, 25), $listedIds($a->request('GET', '/tasks', $query + ['page' => '99'])['body']), 'page past the end → last page');
    LiteAssert::same(array_slice($ids, 0, 25), $listedIds($a->request('GET', '/tasks', $query + ['per_page' => '7'])['body']), 'unknown per_page → default 25');
};

/** The pagination summary as the partial prints it. */
function e_summary(int $first, int $last, int $count): string
{
    return \App\Infrastructure\Theme\e(\App\Infrastructure\Theme\t('pagination.summary', ['first' => $first, 'last' => $last, 'count' => $count]));
}

$tests['TP-3 search + filters + pages keep the scope: no other user\'s task, no "All tasks" without organization scope'] = function () use ($boot, $mk, $listedIds, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $adminOnly = $mk($a, 'LiteTest TP scope admin', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $mine = $mk($a, 'LiteTest TP scope member', (int) $a->member['id'], [(int) $a->member['id']]);
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $adminOnly, $mine, $listedIds, $member) {
        $a->as($member($a));
        foreach ([[], ['view' => 'created'], ['view' => 'archived']] as $q) {
            $ids = $listedIds($a->request('GET', '/tasks', $q + ['q' => 'LiteTest TP scope'])['body']);
            LiteAssert::false(in_array($adminOnly, $ids, true), 'never another user\'s task: ' . json_encode($q));
        }
        LiteAssert::same([$mine], $listedIds($a->request('GET', '/tasks', ['q' => 'LiteTest TP scope'])['body']), 'own task found');
        LiteAssert::same('forbidden', $a->outcome('GET', '/tasks', ['view' => 'all', 'q' => 'LiteTest TP scope', 'page' => '2']), 'no All tasks via search/page params');
    });
    $withScopes($a, ['tasks.view' => 'organization'], function () use ($a, $adminOnly, $listedIds, $member) {
        $a->as($member($a));
        LiteAssert::true(in_array($adminOnly, $listedIds($a->request('GET', '/tasks', ['view' => 'all', 'q' => 'LiteTest TP scope'])['body']), true), 'organization scope: found in All tasks');
    });
};

$tests['TP-4 counts and pages never include tasks hidden by their related project (excluded in SQL, not after paging)'] = function () use ($boot, $t5, $mk, $listedIds, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $x = $t5($a);
    $mid = (int) $a->member['id'];
    $hidden = $mk($a, 'LiteTest TP proj hidden', (int) $a->admin['id'], [$mid], ['related_type' => 'project', 'related_id' => $x->project]);
    $plain = $mk($a, 'LiteTest TP proj plain', (int) $a->admin['id'], [$mid]);
    $withScopes($a, ['projects.view' => null], function () use ($a, $hidden, $plain, $listedIds, $member) {
        $a->as($member($a));
        $page = $a->request('GET', '/tasks', ['q' => 'LiteTest TP proj'])['body'];
        LiteAssert::same([$plain], $listedIds($page), 'only the visible task');
        LiteAssert::contains(e_summary(1, 1, 1), $page, 'the total does not count the hidden task');
    });
    $withScopes($a, ['projects.view' => 'organization'], function () use ($a, $hidden, $plain, $listedIds, $member) {
        $a->as($member($a));
        $ids = $listedIds($a->request('GET', '/tasks', ['q' => 'LiteTest TP proj'])['body']);
        sort($ids);
        $both = [$hidden, $plain];
        sort($both);
        LiteAssert::same($both, $ids, 'with project access: both');
    });
};

$tests['TP-5 labels sit beside the title on list rows (and on the deal page); none for tasks without labels'] = function () use ($boot, $h, $mk, $svc, $labelSvc, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $t = $mk($a, 'LiteTest TP labels', (int) $a->admin['id'], [(int) $a->admin['id']], ['related_type' => 'deal', 'related_id' => $h->f->deal]);
    $bare = $mk($a, 'LiteTest TP no labels', (int) $a->admin['id'], [(int) $a->admin['id']]);
    $l1 = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest TP red', 'color' => 'red'])['id'];
    $l2 = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest TP sky', 'color' => 'sky'])['id'];
    $svc($a)->setLabels($a->admin, $t, [$l1, $l2]);
    foreach (['list' => $a->request('GET', '/tasks', ['q' => 'LiteTest TP labels'])['body'], 'deal page' => $a->request('GET', '/deals/' . $h->f->deal)['body']] as $where => $page) {
        LiteAssert::true((bool) preg_match('#<div class="task-row__head">\s*<a href="/tasks/' . $t . '" class="task-row__title">LiteTest TP labels</a>\s*<span class="task-labels">\s*<span class="task-label task-label--red">LiteTest TP red</span>\s*<span class="task-label task-label--sky">LiteTest TP sky</span>#', $page), "{$where}: title then both pills in one row");
    }
    $a->locale('fa');
    try {
        LiteAssert::contains('task-label task-label--red', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TP labels'])['body'], $t), 'fa (RTL) too');
    } finally {
        $a->locale('en');
    }
    $bareRow = $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TP no labels'])['body'], $bare);
    LiteAssert::true($bareRow !== '', 'bare task listed');
    LiteAssert::notContains('task-labels', $bareRow);
};

$tests['TP-6 return_to keeps an encoded search safely; a failed create re-renders the same search and page'] = function () use ($boot, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    foreach (['/tasks?view=all&q=%D8%B3%D9%84%D8%A7%D9%85+x&page=2', '/tasks?q=a-b.c&per_page=50'] as $ok) {
        LiteAssert::true(TaskController::isSafeReturnTo($ok), $ok);
    }
    foreach (['/tasks?q=<x>', '/tasks?q=a b', '//evil.example?q=1', '/tasks?q=%0D%0A#x'] as $bad) {
        LiteAssert::false(TaskController::isSafeReturnTo($bad), $bad);
    }
    $r = $a->request('POST', '/tasks', [], ['title' => '', 'return_to' => '/tasks?view=all&q=LiteTest+TP&page=1']);
    LiteAssert::same(422, $r['status']);
    LiteAssert::contains('name="q" value="LiteTest TP"', $r['body'], 'search kept after a failed create');
    LiteAssert::contains('name="return_to" value="/tasks?view=all&amp;q=LiteTest+TP"', $dialogHtml($r['body']));
};

// ---- T6: in-app task reminders --------------------------------------------------------------
/** The reminder processor with a fixed clock ($utcNow) and every organization in $zone. */
$reminderSvc = fn (LocalApp $a, string $utcNow, string $zone = 'Asia/Tehran'): \Modules\Sales\Services\TaskReminderService => new \Modules\Sales\Services\TaskReminderService(
    new \Modules\Sales\TaskNotificationRepository(),
    new \Modules\Sales\TaskRepository(),
    $svc($a),
    new \App\Infrastructure\Repositories\UserRepository(),
    new \App\Infrastructure\Modules\ModuleAccessResolver(new \App\Infrastructure\Repositories\ModuleRepository(), new \App\Infrastructure\Repositories\OrganizationModuleRepository(), new \App\Infrastructure\Repositories\UserModuleOverrideRepository()),
    new \App\Infrastructure\Time\OrganizationTimezone(fn (int $id): string => $zone, null, fn (): \DateTimeImmutable => new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC')))
);
/** Processes this organization's due reminders at $utcNow (Asia/Tehran = UTC+03:30, no DST). */
$runReminders = fn (LocalApp $a, string $utcNow, string $zone = 'Asia/Tehran', ?int $orgId = null): array => \App\Infrastructure\Repositories\OrganizationContext::runAs(
    $orgId ?? $a->orgId,
    fn () => $reminderSvc($a, $utcNow, $zone)->processCurrentOrganization()
);
$reminderRows = fn (LocalApp $a, int $taskId): array => $a->pdo->query("SELECT id, organization_id, user_id, occurrence, remind_at, read_at FROM task_notifications WHERE task_id = {$taskId} ORDER BY user_id, occurrence")->fetchAll(\PDO::FETCH_ASSOC);
$remindCols = fn (string $at, int $allDay = 0, int $seq = 1): array => ['remind_at' => $at, 'remind_all_day' => $allDay, 'remind_seq' => $seq];
$sortedIds = function (array $ids): array {
    $ids = array_map('intval', $ids);
    sort($ids);

    return $ids;
};

$tests['T6-1 a reminder is set, kept, changed and removed like start/due (local wall clock, as typed); each change is a new occurrence'] = function () use ($boot, $svc, $row) {
    $a = $boot();
    $a->as($a->admin);
    $s = $svc($a);
    $t = (int) $s->create($a->admin, ['title' => 'LiteTest TH T6 set', 'due_at' => '2026-06-10', 'remind_at' => '2026-06-09', 'remind_time' => '08:30'])['id'];
    $r = $row($a, $t);
    LiteAssert::same(['2026-06-09 08:30:00', 0, 1, 0], [$r['remind_at'], (int) $r['remind_all_day'], (int) $r['remind_seq'], (int) $r['remind_fired_seq']], 'timed reminder stored as typed');
    $s->update($a->admin, $t, ['title' => 'LiteTest TH T6 set', 'remind_at' => '2026-06-09', 'remind_time' => '08:30']);
    LiteAssert::same(1, (int) $row($a, $t)['remind_seq'], 'same value: same occurrence');
    $s->update($a->admin, $t, ['title' => 'LiteTest TH T6 set']);
    LiteAssert::same('2026-06-09 08:30:00', $row($a, $t)['remind_at'], 'not submitted: unchanged');
    $s->update($a->admin, $t, ['title' => 'LiteTest TH T6 set', 'remind_at' => '2026-06-09', 'remind_time' => '']);
    $r = $row($a, $t);
    LiteAssert::same(['2026-06-09 00:00:00', 1, 2], [$r['remind_at'], (int) $r['remind_all_day'], (int) $r['remind_seq']], 'date only: all day, a new occurrence');
    $s->update($a->admin, $t, ['title' => 'LiteTest TH T6 set', 'remind_at' => '', 'remind_time' => '']);
    LiteAssert::same(null, $row($a, $t)['remind_at'], 'removed');
    $none = $row($a, (int) $s->create($a->admin, ['title' => 'LiteTest TH T6 none'])['id']);
    LiteAssert::same([null, 0], [$none['remind_at'], (int) $none['remind_seq']], 'a reminder is optional');
    // Before the start and the due date is fine; T2's start <= due rule still applies.
    $early = $s->create($a->admin, ['title' => 'LiteTest TH T6 early', 'start_at' => '2026-06-05', 'due_at' => '2026-06-10', 'remind_at' => '2026-06-01', 'remind_time' => '09:00']);
    LiteAssert::same('2026-06-01 09:00:00', $early['remind_at']);
    try {
        $s->create($a->admin, ['title' => 'LiteTest TH T6 early', 'start_at' => '2026-06-11', 'due_at' => '2026-06-10', 'remind_at' => '2026-06-01']);
        throw new \RuntimeException('start after due accepted');
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors['start_at']), 'T2 constraint intact');
    }
};

$tests['T6-2 reminder validation: a time without a date, invalid dates/times and an ambiguous date+time are rejected; nothing written'] = function () use ($boot, $svc, $invalid, $row, $count) {
    $a = $boot();
    $a->as($a->admin);
    $s = $svc($a);
    $bad = 'LiteTest TH T6 bad';
    $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_time' => '09:00']), 'remind_time', 'time, no date field');
    $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_at' => '', 'remind_time' => '09:00']), 'remind_time', 'time, empty date');
    foreach (['2026-02-30', '30/01/2026', 'tomorrow', '2026-1-5'] as $value) {
        $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_at' => $value]), 'remind_at', $value);
    }
    foreach (['24:00', '9:00', '09:60', 'x'] as $value) {
        $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_at' => '2026-06-01', 'remind_time' => $value]), 'remind_time', $value);
    }
    $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_at' => '2026-06-01 10:00', 'remind_time' => '09:00']), 'remind_at', 'date with a time + a time');
    $invalid(fn () => $s->create($a->admin, ['title' => $bad, 'remind_at' => ['2026-06-01']]), 'remind_at', 'array');
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title = '{$bad}'"), 'nothing created');
    $t = (int) $s->create($a->admin, ['title' => 'LiteTest TH T6 keep', 'remind_at' => '2026-06-01', 'remind_time' => '07:00'])['id'];
    $invalid(fn () => $s->update($a->admin, $t, ['title' => 'LiteTest TH T6 keep', 'remind_at' => '2026-13-01']), 'remind_at', 'update');
    LiteAssert::same(['2026-06-01 07:00:00', 1], [$row($a, $t)['remind_at'], (int) $row($a, $t)['remind_seq']], 'unchanged after a failed edit');
};

$tests['T6-3 dialog and edit form: reminder datepicker + time; a failed create or edit (422) keeps the submitted values and shows the error under the field'] = function () use ($boot, $svc, $dialogHtml, $row) {
    $a = $boot();
    $a->as($a->admin);
    $tr = fn (string $key): string => \App\Infrastructure\Theme\t($key);
    $dialog = $dialogHtml($a->request('GET', '/tasks')['body']);
    LiteAssert::contains('<input type="text" class="crm-datepicker" id="task-new-remind" name="remind_at" value="">', $dialog);
    LiteAssert::contains('<input type="time" class="field-sm" id="task-new-remind-time" name="remind_time" value="">', $dialog);
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T6 dialog', 'description' => 'LiteTest kept', 'remind_time' => '09:15', 'return_to' => '/tasks']);
    LiteAssert::same(422, $r['status']);
    $dialog = $dialogHtml($r['body']);
    LiteAssert::contains('name="remind_time" value="09:15">' . "\n" . '            <div class="field-error">' . $tr('tasks.error.time_without_date') . '</div>', $dialog, 'error under the reminder time');
    LiteAssert::contains('value="LiteTest TH T6 dialog"', $dialog, 'title kept');
    LiteAssert::contains('LiteTest kept</textarea>', $dialog, 'description kept');
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T6 dialog', 'remind_at' => '2026-02-30', 'remind_time' => '08:00', 'return_to' => '/tasks']);
    $dialog = $dialogHtml($r['body']);
    LiteAssert::contains('name="remind_at" value="2026-02-30">' . "\n" . '            <div class="field-error">' . $tr('tasks.error.invalid_remind_date') . '</div>', $dialog, 'error under the reminder date');
    LiteAssert::contains('name="remind_time" value="08:00">', $dialog, 'time kept');

    $t = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T6 edit', 'due_at' => '2026-06-10', 'remind_at' => '2026-06-08', 'remind_time' => '10:00'])['id'];
    $show = $a->request('GET', "/tasks/{$t}")['body'];
    LiteAssert::contains('<input type="text" class="crm-datepicker" id="remind_at" name="remind_at" value="2026-06-08">', $show);
    LiteAssert::contains('<input type="time" class="field-sm" id="remind_time" name="remind_time" value="10:00">', $show);
    $r = $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T6 edit changed', 'due_at' => '2026-06-10', 'remind_at' => '2026-06-09', 'remind_time' => '25:00']);
    LiteAssert::same(422, $r['status'], 'the edit form is shown again');
    LiteAssert::contains('value="LiteTest TH T6 edit changed"', $r['body'], 'submitted title kept');
    LiteAssert::contains('name="remind_at" value="2026-06-09">', $r['body'], 'submitted reminder date kept');
    LiteAssert::contains('name="remind_time" value="25:00">' . "\n" . '        <div class="field-error">' . $tr('tasks.error.invalid_time') . '</div>', $r['body'], 'error under the field');
    LiteAssert::same(['LiteTest TH T6 edit', '2026-06-08 10:00:00'], [$row($a, $t)['title'], $row($a, $t)['remind_at']], 'nothing saved');
    $r = $a->request('POST', "/tasks/{$t}", [], ['title' => 'LiteTest TH T6 edit', 'due_at' => '2026-06-10', 'remind_at' => '', 'remind_time' => '']);
    LiteAssert::same(302, $r['status']);
    LiteAssert::same(null, $row($a, $t)['remind_at'], 'removed from the edit form');
};

$tests['T6-4 a reminder is due by its organization\'s own clock (Asia/Tehran vs UTC), never the server\'s; all day = from 00:00 local'] = function () use ($boot, $mk, $runReminders, $reminderRows, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $t = $mk($a, 'LiteTest TH T6 clock', $aid, [$aid], $remindCols('2026-01-15 10:00:00'));
    $runReminders($a, '2026-01-15 06:29:00'); // 09:59 in Tehran
    LiteAssert::same([], $reminderRows($a, $t), 'one minute early: nothing');
    $runReminders($a, '2026-01-15 06:30:00', 'UTC'); // the same instant in a UTC organization is 06:30
    LiteAssert::same([], $reminderRows($a, $t), 'a UTC organization: not due yet');
    $runReminders($a, '2026-01-15 06:30:00'); // 10:00 in Tehran
    LiteAssert::same(1, count($reminderRows($a, $t)), 'due at 10:00 Tehran time');
    LiteAssert::same('2026-01-15 10:00:00', $reminderRows($a, $t)[0]['remind_at'], 'the occurrence keeps its local time');

    $day = $mk($a, 'LiteTest TH T6 all day', $aid, [$aid], $remindCols('2026-01-16 00:00:00', 1));
    $runReminders($a, '2026-01-15 20:29:00'); // 23:59 local
    LiteAssert::same([], $reminderRows($a, $day), 'all day: not the evening before');
    $runReminders($a, '2026-01-15 20:30:00'); // 00:00 local on the 16th (still the 15th in UTC)
    LiteAssert::same(1, count($reminderRows($a, $day)), 'all day: from the start of that local day');
};

$tests['T6-5 exactly one notification per assignee and occurrence: re-runs, a late second claim and duplicate inserts add nothing; a changed reminder fires once more'] = function () use ($boot, $mk, $svc, $row, $runReminders, $reminderRows, $remindCols, $sortedIds) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $t = $mk($a, 'LiteTest TH T6 once', $aid, [$aid, $mid], $remindCols('2026-01-15 09:00:00'));
    $runReminders($a, '2026-01-15 06:00:00');
    LiteAssert::same($sortedIds([$aid, $mid]), $sortedIds(array_column($reminderRows($a, $t), 'user_id')), 'both assignees, once each');
    LiteAssert::same(1, (int) $row($a, $t)['remind_fired_seq'], 'occurrence handled');
    foreach (['2026-01-15 06:00:00', '2026-01-15 06:01:00', '2026-02-01 00:00:00'] as $later) {
        $runReminders($a, $later);
    }
    LiteAssert::same(2, count($reminderRows($a, $t)), 're-runs add nothing');
    $repo = new \Modules\Sales\TaskNotificationRepository();
    LiteAssert::false(\App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $repo->claim($t, 1)), 'a second processor cannot claim it again');
    LiteAssert::false(\App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $repo->addReminder($row($a, $t), $aid)), 'a duplicate insert is ignored');
    LiteAssert::same(2, count($reminderRows($a, $t)));

    $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH T6 once', 'remind_at' => '2026-01-15', 'remind_time' => '11:00']);
    $runReminders($a, '2026-01-15 07:00:00'); // 10:30 local
    LiteAssert::same(2, count($reminderRows($a, $t)), 'the new time is not due yet');
    $runReminders($a, '2026-01-15 07:30:00'); // 11:00 local
    $runReminders($a, '2026-01-15 07:31:00');
    $rows = $reminderRows($a, $t);
    LiteAssert::same(['2026-01-15 09:00:00', '2026-01-15 11:00:00', '2026-01-15 09:00:00', '2026-01-15 11:00:00'], array_column($rows, 'remind_at'), 'one more each for the new occurrence');

    // Several due reminders in one run, one task with no assignee (its creator is reminded).
    $one = $mk($a, 'LiteTest TH T6 multi 1', $aid, [$mid], $remindCols('2026-01-20 08:00:00'));
    $two = $mk($a, 'LiteTest TH T6 multi 2', $aid, [], $remindCols('2026-01-20 08:30:00'));
    $result = $runReminders($a, '2026-01-20 06:00:00');
    LiteAssert::true($result['reminders'] >= 2 && $result['notifications'] >= 2, json_encode($result));
    LiteAssert::same([$mid], $sortedIds(array_column($reminderRows($a, $one), 'user_id')));
    LiteAssert::same([$aid], $sortedIds(array_column($reminderRows($a, $two), 'user_id')), 'no assignee: the creator');
};

$tests['T6-6 the reminders page: own reminders only, with the task link, title and reminder time; unread count, mark read, read all, opening the task; CSRF; module gate'] = function () use ($boot, $mk, $member, $runReminders, $reminderRows, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $tr = fn (string $key): string => \App\Infrastructure\Theme\t($key);
    $t = $mk($a, 'LiteTest TH T6 inbox <b>', $aid, [$aid, $mid], $remindCols('2026-01-15 09:00:00'));
    $runReminders($a, '2026-01-15 06:00:00');
    $ids = array_column($reminderRows($a, $t), 'id', 'user_id');
    [$adminN, $memberN] = [(int) $ids[$aid], (int) $ids[$mid]];

    $page = $a->request('GET', '/tasks/reminders')['body'];
    LiteAssert::contains('<a href="/tasks/' . $t . '">', $page, 'links back to the task');
    LiteAssert::contains('LiteTest TH T6 inbox &lt;b&gt;', $page, 'title, escaped');
    LiteAssert::notContains('LiteTest TH T6 inbox <b>', $page);
    LiteAssert::contains((new \App\Infrastructure\Localization\LocaleFormatter('en'))->date('2026-01-15') . ' 09:00', $page, 'the reminder time');
    LiteAssert::contains($tr('tasks.reminder_unread'), $page);
    LiteAssert::contains('action="/tasks/reminders/' . $adminN . '/read"', $page);
    LiteAssert::notContains('/tasks/reminders/' . $memberN . '/read', $page, 'not the member\'s');
    LiteAssert::true(preg_match('#href="/tasks/reminders">.*?<span class="badge badge--info badge--plain">(\d+)</span>#s', $a->request('GET', '/tasks')['body'], $m) === 1 && (int) $m[1] >= 1, 'unread count on the task list');

    $a->as($member($a));
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/reminders/{$adminN}/read"), 'someone else\'s notification');
    LiteAssert::same('notfound', $a->outcome('POST', '/tasks/reminders/999999999/read'), 'unknown');
    LiteAssert::same('csrf', $a->outcome('POST', "/tasks/reminders/{$memberN}/read", [], ['_csrf' => 'wrong']), 'CSRF: read');
    LiteAssert::same('csrf', $a->outcome('POST', '/tasks/reminders/read-all', [], ['_csrf' => '']), 'CSRF: read all');
    $read = fn (int $id) => $a->pdo->query("SELECT read_at FROM task_notifications WHERE id = {$id}")->fetchColumn();
    LiteAssert::same([null, null], [$read($adminN), $read($memberN)], 'nothing read yet');
    $r = $a->request('POST', "/tasks/reminders/{$memberN}/read");
    LiteAssert::same([302, '/tasks/reminders'], [$r['status'], $r['location']]);
    LiteAssert::true($read($memberN) !== null && $read($adminN) === null, 'only the member\'s own one');
    LiteAssert::contains($tr('tasks.reminder_read'), $a->request('GET', '/tasks/reminders')['body']);

    $a->as($a->admin);
    $a->request('GET', "/tasks/{$t}");
    LiteAssert::true($read($adminN) !== null, 'opening the task reads its reminders');
    $more = $mk($a, 'LiteTest TH T6 inbox 2', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $other = $mk($a, 'LiteTest TH T6 inbox 3', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $runReminders($a, '2026-01-15 06:00:00');
    $a->request('POST', '/tasks/reminders/read-all');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM task_notifications WHERE user_id = {$aid} AND organization_id = {$a->orgId} AND read_at IS NULL")->fetchColumn(), 'all read');
    LiteAssert::false(preg_match('#href="/tasks/reminders">[^<]*<svg[^>]*><use[^>]*></use></svg>[^<]*<span class="badge#', $a->request('GET', '/tasks')['body']) === 1, 'no unread count');

    $a->setOrgEntitled('sales', false);
    try {
        LiteAssert::same('notfound', $a->outcome('GET', '/tasks/reminders'), 'Sales off: no reminders page');
    } finally {
        $a->setOrgEntitled('sales', true);
    }
};

$tests['T6-7 nobody is notified about a task they may not see; inactive and foreign users never; another organization is untouched and its rows unreachable'] = function () use ($boot, $h, $mk, $t5, $withScopes, $member, $row, $runReminders, $reminderRows, $remindCols, $svc, $reminderSvc) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $x = $t5($a);
    $withScopes($a, ['projects.view' => null], function () use ($a, $x, $aid, $mid, $mk, $row, $runReminders, $reminderRows, $remindCols) {
        $p = $mk($a, 'LiteTest TH T6 hidden project', $aid, [$mid], ['related_type' => 'project', 'related_id' => $x->project] + $remindCols('2026-01-15 09:00:00'));
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same([], $reminderRows($a, $p), 'the member may not see the project: no notification');
        LiteAssert::same(1, (int) $row($a, $p)['remind_fired_seq'], 'the occurrence is handled anyway');
    });
    $withScopes($a, ['tasks.view' => null], function () use ($a, $aid, $mid, $mk, $runReminders, $reminderRows, $remindCols) {
        $v = $mk($a, 'LiteTest TH T6 no view', $aid, [$mid], $remindCols('2026-01-15 09:00:00'));
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same([], $reminderRows($a, $v), 'no tasks.view: no notification');
    });
    $a->pdo->prepare("INSERT INTO users (organization_id, first_name, last_name, email, password_hash, status) VALUES (?, 'LiteTestInactive', 'T6', ?, 'x', 'inactive')")
        ->execute([$a->orgId, 'litetest-th-t6-' . bin2hex(random_bytes(4)) . '@example.test']);
    $inactive = (int) $a->pdo->lastInsertId();
    $i = $mk($a, 'LiteTest TH T6 inactive', $aid, [$inactive], $remindCols('2026-01-15 09:00:00'));
    $runReminders($a, '2026-01-15 06:00:00');
    LiteAssert::same([], $reminderRows($a, $i), 'inactive assignee: no notification');

    // A notification shown earlier disappears once its task is no longer visible.
    $later = $mk($a, 'LiteTest TH T6 later hidden', $aid, [$mid], ['related_type' => 'project', 'related_id' => $x->project] + $remindCols('2026-01-15 09:00:00'));
    $withScopes($a, ['projects.view' => 'organization'], function () use ($a, $later, $member, $runReminders, $reminderRows, $reminderSvc) {
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same(1, count($reminderRows($a, $later)), 'visible then: notified');
        $a->as($member($a));
        LiteAssert::contains('LiteTest TH T6 later hidden', $a->request('GET', '/tasks/reminders')['body']);
    });
    $withScopes($a, ['projects.view' => null], function () use ($a, $member) {
        $a->as($member($a));
        LiteAssert::notContains('LiteTest TH T6 later hidden', $a->request('GET', '/tasks/reminders')['body'], 'hidden now: nothing about it shows');
    });

    if ($h->f->foreignUser !== null) {
        $f = $mk($a, 'LiteTest TH T6 foreign assignee', $aid, [(int) $h->f->foreignUser], $remindCols('2026-01-15 09:00:00'));
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same([], $reminderRows($a, $f), 'another organization\'s user: no notification');

        $foreignTask = $mk($a, 'LiteTest TH T6 foreign task', (int) $h->f->foreignUser, [(int) $h->f->foreignUser], ['organization_id' => $a->otherOrgId] + $remindCols('2026-01-15 09:00:00'));
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same([0, []], [(int) $row($a, $foreignTask)['remind_fired_seq'], $reminderRows($a, $foreignTask)], 'this organization\'s run does not touch it');
        $runReminders($a, '2026-01-15 06:00:00', 'Asia/Tehran', $a->otherOrgId);
        LiteAssert::same(1, (int) $row($a, $foreignTask)['remind_fired_seq'], 'handled in its own organization');
        foreach ($reminderRows($a, $foreignTask) as $n) {
            LiteAssert::same($a->otherOrgId, (int) $n['organization_id'], 'stays in its organization');
        }
        // A row of another organization, even one carrying this admin's user id, is unreachable.
        $a->pdo->prepare("INSERT INTO task_notifications (organization_id, user_id, task_id, kind, occurrence, remind_at) VALUES (?, ?, ?, 'reminder', 99, '2026-01-15 09:00:00')")
            ->execute([$a->otherOrgId, $aid, $foreignTask]);
        $planted = (int) $a->pdo->lastInsertId();
        $a->as($a->admin);
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/reminders/{$planted}/read"));
        $a->request('POST', '/tasks/reminders/read-all');
        LiteAssert::same(null, $a->pdo->query("SELECT read_at FROM task_notifications WHERE id = {$planted}")->fetchColumn() ?: null, 'read-all stays in this organization');
        LiteAssert::notContains('LiteTest TH T6 foreign task', $a->request('GET', '/tasks/reminders')['body']);
    }
};

$tests['T6-8 archived, closed and deleted tasks notify nobody (occurrence handled); a deleted task\'s notification leaves the inbox, an archived one stays'] = function () use ($boot, $mk, $row, $runReminders, $reminderRows, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $cases = [
        'archived' => ['archived_at' => '2026-01-01 00:00:00'],
        'deleted' => ['deleted_at' => '2026-01-01 00:00:00', 'deleted_by_user_id' => $aid],
        'completed' => ['status' => 'completed'],
        'cancelled' => ['status' => 'cancelled'],
    ];
    foreach ($cases as $label => $extra) {
        $id = $mk($a, "LiteTest TH T6 {$label}", $aid, [$aid], $extra + $remindCols('2026-01-15 09:00:00'));
        $runReminders($a, '2026-01-15 06:00:00');
        LiteAssert::same([[], 1], [$reminderRows($a, $id), (int) $row($a, $id)['remind_fired_seq']], $label);
    }
    $gone = $mk($a, 'LiteTest TH T6 gone later', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $kept = $mk($a, 'LiteTest TH T6 archived later', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $runReminders($a, '2026-01-15 06:00:00');
    $a->pdo->exec("UPDATE tasks SET deleted_at = '2026-01-16 00:00:00' WHERE id = {$gone}");
    $a->pdo->exec("UPDATE tasks SET archived_at = '2026-01-16 00:00:00' WHERE id = {$kept}");
    $page = $a->request('GET', '/tasks/reminders')['body'];
    LiteAssert::notContains('LiteTest TH T6 gone later', $page, 'deleted: not listed');
    LiteAssert::notContains('/tasks/' . $gone . '"', $page);
    LiteAssert::contains('LiteTest TH T6 archived later', $page, 'archived: still listed');
};

$tests['T6-9 list row bell + reminder time, task page status (scheduled → sent, none); fa (RTL) too; translations in all five languages'] = function () use ($boot, $svc, $rowHtml, $runReminders) {
    $a = $boot();
    $a->as($a->admin);
    $tr = fn (string $key): string => \App\Infrastructure\Theme\t($key);
    $t = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T6 ui', 'remind_at' => '2026-01-15', 'remind_time' => '09:00'])['id'];
    $plain = (int) $svc($a)->create($a->admin, ['title' => 'LiteTest TH T6 ui plain'])['id'];
    $fmt = new \App\Infrastructure\Localization\LocaleFormatter('en');
    $html = $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T6 ui'])['body'], $t);
    LiteAssert::contains('<span class="task-row__reminder" title="' . $tr('tasks.reminder') . ' — ' . $tr('tasks.reminder_state.scheduled') . '"><svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#i-bell"></use></svg><span class="ltr">' . $fmt->date('2026-01-15') . ' 09:00</span></span>', $html, 'bell + time');
    LiteAssert::notContains('task-row__reminder', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T6 ui plain'])['body'], $plain), 'no reminder: no bell');
    LiteAssert::contains($tr('tasks.reminder_state.scheduled'), $a->request('GET', "/tasks/{$t}")['body']);
    LiteAssert::contains($tr('tasks.reminder_none'), $a->request('GET', "/tasks/{$plain}")['body']);
    $runReminders($a, '2026-01-15 06:00:00');
    LiteAssert::contains('task-row__reminder task-row__reminder--fired', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T6 ui'])['body'], $t), 'sent');
    LiteAssert::contains($tr('tasks.reminder_state.delivered'), $a->request('GET', "/tasks/{$t}")['body']);
    $a->locale('fa');
    try {
        $page = $a->request('GET', "/tasks/{$t}")['body'];
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.remind_date'), $page, 'fa label');
        LiteAssert::contains('<span class="ltr">' . (new \App\Infrastructure\Localization\LocaleFormatter('fa'))->date('2026-01-15'), $page, 'Jalali date');
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.reminders'), $a->request('GET', '/tasks/reminders')['body']);
    } finally {
        $a->locale('en');
    }
    $keys = ['tasks.remind_date', 'tasks.remind_time', 'tasks.remind_hint', 'tasks.reminder', 'tasks.reminder_none', 'tasks.reminder_state.scheduled',
        'tasks.reminder_state.delivered', 'tasks.reminders', 'tasks.reminders_hint', 'tasks.reminders_read_all', 'tasks.reminders_empty',
        'tasks.reminder_received', 'tasks.reminder_unread', 'tasks.reminder_read', 'tasks.reminder_mark_read', 'tasks.flash.reminders_read', 'tasks.error.invalid_remind_date'];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        foreach ($keys as $key) {
            LiteAssert::true(isset($strings[$key]) && trim($strings[$key]) !== '', "{$lang}: {$key}");
        }
    }
};

$tests['T6-10 the cron processor: CLI only, lock file, bound in the container, runAll() uses the real clock; migration 0011 is additive'] = function () use ($boot, $mk, $reminderRows, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $root = dirname(__DIR__, 2);
    $script = file_get_contents($root . '/modules/Sales/bin/task-reminders.php');
    foreach (["PHP_SAPI !== 'cli'", 'LOCK_EX | LOCK_NB', 'storage/cache/task-reminders.lock', 'TaskReminderService::class', '$service->runAll()'] as $needle) {
        LiteAssert::contains($needle, $script, $needle);
    }
    LiteAssert::true($a->container()->has(\Modules\Sales\Services\TaskReminderService::class), 'bound for the script');
    $aid = (int) $a->admin['id'];
    $past = $mk($a, 'LiteTest TH T6 cron past', $aid, [$aid], $remindCols('2020-01-01 09:00:00'));
    $future = $mk($a, 'LiteTest TH T6 cron future', $aid, [$aid], $remindCols('2099-01-01 09:00:00'));
    $stats = $a->container()->make(\Modules\Sales\Services\TaskReminderService::class)->runAll();
    LiteAssert::same([], $stats['errors']);
    LiteAssert::true($stats['organizations'] >= 1 && $stats['reminders'] >= 1, json_encode($stats));
    LiteAssert::same(1, count($reminderRows($a, $past)), 'a past reminder fires');
    LiteAssert::same([], $reminderRows($a, $future), 'a future one waits');
    $up = explode('public function down', file_get_contents($root . '/modules/Sales/Migrations/0011_add_task_reminders.php'))[0];
    LiteAssert::false((bool) preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $up), '0011 up() is additive');
};

// ---- T7: email reminders + recurring tasks ------------------------------------------------------
/** A mailer that records what it was given, or fails like an unreachable SMTP server. */
$fakeMailer = fn (bool $fail = false): object => new class($fail) implements \App\Infrastructure\Mail\MailerInterface {
    public array $sent = [];

    public function __construct(public bool $fail)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) {
            throw new \RuntimeException('LiteTest SMTP unreachable');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
};
$clockAt = fn (string $utcNow, string $zone = 'Asia/Tehran'): \App\Infrastructure\Time\OrganizationTimezone => new \App\Infrastructure\Time\OrganizationTimezone(
    fn (int $id): string => $zone, null, fn (): \DateTimeImmutable => new \DateTimeImmutable($utcNow, new \DateTimeZone('UTC'))
);
$emailSvc = fn (LocalApp $a, ?object $mailer, string $utcNow, bool $ready = true, string $zone = 'Asia/Tehran'): \Modules\Sales\Services\TaskReminderService => new \Modules\Sales\Services\TaskReminderService(
    new \Modules\Sales\TaskNotificationRepository(),
    new \Modules\Sales\TaskRepository(),
    $svc($a),
    new \App\Infrastructure\Repositories\UserRepository(),
    new \App\Infrastructure\Modules\ModuleAccessResolver(new \App\Infrastructure\Repositories\ModuleRepository(), new \App\Infrastructure\Repositories\OrganizationModuleRepository(), new \App\Infrastructure\Repositories\UserModuleOverrideRepository()),
    $clockAt($utcNow, $zone),
    $mailer,
    fn (): bool => $ready,
    'https://crm.example.test',
    ''
);
/** Reminders, then their emails, for this organization at $utcNow; returns the email result. */
$runEmails = fn (LocalApp $a, ?object $mailer, string $utcNow, bool $ready = true, string $zone = 'Asia/Tehran'): array => \App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, function () use ($a, $mailer, $utcNow, $ready, $zone, $emailSvc) {
    $service = $emailSvc($a, $mailer, $utcNow, $ready, $zone);
    $service->processCurrentOrganization();

    return $service->deliverEmails();
});
$emailRows = fn (LocalApp $a, int $taskId): array => $a->pdo->query("SELECT id, user_id, email_status, email_attempts, email_next_at, email_sent_at, email_error, email_lock FROM task_notifications WHERE task_id = {$taskId} ORDER BY user_id")->fetchAll(\PDO::FETCH_ASSOC);
$sentTo = fn (object $mailer, string $title): array => array_values(array_filter($mailer->sent, fn (array $m) => str_contains($m['subject'], $title)));
$english = function (LocalApp $a, int ...$userIds): void {
    foreach ($userIds as $id) {
        $a->pdo->exec("UPDATE users SET language = 'en' WHERE id = {$id}");
    }
};

$recurSvc = fn (LocalApp $a, string $utcNow, string $zone = 'Asia/Tehran'): \Modules\Sales\Services\TaskRecurrenceService => new \Modules\Sales\Services\TaskRecurrenceService(
    new \Modules\Sales\TaskRecurrenceRepository(),
    new \Modules\Sales\TaskRepository(),
    $svc($a),
    new \App\Infrastructure\Repositories\UserRepository(),
    $clockAt($utcNow, $zone)
);
$runRecurrence = fn (LocalApp $a, string $utcNow, string $zone = 'Asia/Tehran', ?int $orgId = null): array => \App\Infrastructure\Repositories\OrganizationContext::runAs(
    $orgId ?? $a->orgId,
    fn () => $recurSvc($a, $utcNow, $zone)->processCurrentOrganization()
);
$recurCols = fn (string $type, int $interval, string $anchor, ?string $until = null, int $active = 1): array => [
    'recur_type' => $type, 'recur_interval' => $interval, 'recur_until' => $until, 'recur_active' => $active, 'recur_anchor' => $anchor, 'recur_index' => 0,
];
$childOf = fn (LocalApp $a, int $id): array|false => $a->pdo->query("SELECT * FROM tasks WHERE recur_parent_id = {$id}")->fetch(\PDO::FETCH_ASSOC);
$childCount = fn (LocalApp $a, int $id): int => (int) $a->pdo->query("SELECT COUNT(*) FROM tasks WHERE recur_parent_id = {$id}")->fetchColumn();

$tests['T7-E1 an email reminder sends one email per assignee: title, local reminder time + zone, due date, task link; re-runs send nothing more'] = function () use ($boot, $mk, $fakeMailer, $runEmails, $emailRows, $sentTo, $english, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $english($a, $aid, $mid);
    $t = $mk($a, 'LiteTest TH T7 mail', $aid, [$aid, $mid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1, 'due_at' => '2026-01-16 00:00:00', 'due_all_day' => 1]);
    $mailer = $fakeMailer();
    $result = $runEmails($a, $mailer, '2026-01-15 05:30:00'); // 09:00 in Tehran
    $mails = $sentTo($mailer, 'LiteTest TH T7 mail');
    LiteAssert::same(2, count($mails), 'one email per assignee');
    $emails = array_map(fn (array $m) => $m['to'], $mails);
    sort($emails);
    $expected = [(string) $a->admin['email'], (string) $a->member['email']];
    sort($expected);
    LiteAssert::same($expected, $emails);
    $fmt = new \App\Infrastructure\Localization\LocaleFormatter('en');
    $body = $mails[0]['body'];
    LiteAssert::same('Reminder: LiteTest TH T7 mail', $mails[0]['subject']);
    LiteAssert::contains('Title: LiteTest TH T7 mail', $body);
    LiteAssert::contains('Reminder: ' . $fmt->date('2026-01-15') . ' 09:00 (Asia/Tehran)', $body, 'organization-local time and zone');
    LiteAssert::contains($fmt->date('2026-01-16'), $body, 'due date');
    LiteAssert::contains('https://crm.example.test/tasks/' . $t, $body, 'link to the task');
    LiteAssert::true($result['sent'] >= 2);
    foreach ($emailRows($a, $t) as $row) {
        LiteAssert::same(['sent', 1, null], [$row['email_status'], (int) $row['email_attempts'], $row['email_lock']]);
        LiteAssert::true($row['email_sent_at'] !== null);
    }
    foreach (['2026-01-15 05:30:00', '2026-01-15 05:31:00', '2026-01-20 00:00:00'] as $later) {
        $runEmails($a, $mailer, $later);
    }
    LiteAssert::same(2, count($sentTo($mailer, 'LiteTest TH T7 mail')), 're-runs send nothing more');
    $repo = new \Modules\Sales\TaskNotificationRepository();
    $id = (int) $emailRows($a, $t)[0]['id'];
    LiteAssert::false(\App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $repo->claimEmail($id, 'x', '2026-01-20 00:00:00', '2026-01-19 00:00:00')), 'a sent email cannot be claimed again');
};

$tests['T7-E2 email off: the in-app reminder still comes, no email; a UTC organization gets its own local time in the email'] = function () use ($boot, $mk, $fakeMailer, $runEmails, $emailRows, $sentTo, $english, $remindCols, $reminderRows) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $english($a, $aid);
    $off = $mk($a, 'LiteTest TH T7 no mail', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $mailer = $fakeMailer();
    $runEmails($a, $mailer, '2026-01-15 05:30:00');
    LiteAssert::same(1, count($reminderRows($a, $off)), 'in-app reminder');
    LiteAssert::same('none', $emailRows($a, $off)[0]['email_status']);
    LiteAssert::same([], $sentTo($mailer, 'LiteTest TH T7 no mail'), 'no email');

    $utc = $mk($a, 'LiteTest TH T7 utc org', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $runEmails($a, $mailer, '2026-01-15 05:30:00', true, 'UTC'); // 05:30 there: not due
    LiteAssert::same([], $sentTo($mailer, 'LiteTest TH T7 utc org'), 'not due by the UTC organization\'s clock');
    $runEmails($a, $mailer, '2026-01-15 09:00:00', true, 'UTC');
    $mails = $sentTo($mailer, 'LiteTest TH T7 utc org');
    LiteAssert::same(1, count($mails));
    LiteAssert::contains(' 09:00 (UTC)', $mails[0]['body'], 'wall clock as typed, with the organization\'s zone');
};

$tests['T7-E3 a failed send is retried later with back-off (never marked sent), succeeds once; gives up after the attempt limit; no mailer configured = nothing attempted'] = function () use ($boot, $mk, $fakeMailer, $runEmails, $emailRows, $sentTo, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $t = $mk($a, 'LiteTest TH T7 retry', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $down = $fakeMailer(true);
    $result = $runEmails($a, $down, '2026-01-15 05:30:00');
    $row = $emailRows($a, $t)[0];
    LiteAssert::same(['pending', 1, 'LiteTest SMTP unreachable', '2026-01-15 05:35:00', null, null], [$row['email_status'], (int) $row['email_attempts'], $row['email_error'], $row['email_next_at'], $row['email_sent_at'], $row['email_lock']]);
    LiteAssert::true($result['retry'] >= 1);
    $up = $fakeMailer();
    $runEmails($a, $up, '2026-01-15 05:34:00');
    LiteAssert::same([], $sentTo($up, 'LiteTest TH T7 retry'), 'not before its retry time');
    $runEmails($a, $up, '2026-01-15 05:35:00');
    LiteAssert::same(1, count($sentTo($up, 'LiteTest TH T7 retry')), 'retried and sent');
    LiteAssert::same(['sent', 2], [$emailRows($a, $t)[0]['email_status'], (int) $emailRows($a, $t)[0]['email_attempts']]);
    $runEmails($a, $up, '2026-01-16 00:00:00');
    LiteAssert::same(1, count($sentTo($up, 'LiteTest TH T7 retry')), 'once');

    $g = $mk($a, 'LiteTest TH T7 give up', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    foreach (['2026-01-15 05:30:00', '2026-01-15 05:35:00', '2026-01-15 05:45:00', '2026-01-15 06:05:00', '2026-01-15 06:45:00', '2026-01-16 00:00:00'] as $when) {
        $runEmails($a, $down, $when);
    }
    $row = $emailRows($a, $g)[0];
    LiteAssert::same(['failed', \Modules\Sales\Services\TaskReminderService::MAX_EMAIL_ATTEMPTS, null], [$row['email_status'], (int) $row['email_attempts'], $row['email_sent_at']], 'gave up, never marked sent');

    $n = $mk($a, 'LiteTest TH T7 no mailer', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $mailer = $fakeMailer();
    $runEmails($a, $mailer, '2026-01-15 05:30:00', false);
    $row = $emailRows($a, $n)[0];
    LiteAssert::same(['pending', 0, 'mail not configured', null], [$row['email_status'], (int) $row['email_attempts'], $row['email_error'], $row['email_sent_at']]);
    LiteAssert::same([], $sentTo($mailer, 'LiteTest TH T7 no mailer'), 'nothing attempted');
    $runEmails($a, $mailer, '2026-01-15 05:46:00', true);
    LiteAssert::same(1, count($sentTo($mailer, 'LiteTest TH T7 no mailer')), 'sent once a mailer is configured');
    $old = $mk($a, 'LiteTest TH T7 expired', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $runEmails($a, $mailer, '2026-01-15 05:30:00', false);
    $a->pdo->exec("UPDATE task_notifications SET created_at = '2026-01-12 00:00:00', email_next_at = NULL WHERE task_id = {$old}");
    $runEmails($a, $mailer, '2026-01-15 06:00:00', false);
    LiteAssert::same(['failed', 'expired: mail not configured'], [$emailRows($a, $old)[0]['email_status'], $emailRows($a, $old)[0]['email_error']]);
    LiteAssert::same([], $sentTo($mailer, 'LiteTest TH T7 expired'));
    LiteAssert::same(\App\Infrastructure\Config\Config::get('app.env') !== 'production', \Modules\Sales\Services\TaskReminderService::mailConfigured() || \App\Infrastructure\Config\Config::get('mail.driver') !== 'log', 'the log driver counts only outside production');
};

$tests['T7-E4 the email never shows a related record the recipient may not see; a task deleted or closed before sending sends nothing'] = function () use ($boot, $h, $mk, $withScopes, $fakeMailer, $runEmails, $emailRows, $sentTo, $english, $remindCols) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $english($a, $aid, $mid);
    $dealName = (string) $a->pdo->query('SELECT name FROM deals WHERE id = ' . (int) $h->f->deal)->fetchColumn();
    $t = $mk($a, 'LiteTest TH T7 related', $aid, [$aid, $mid], ['related_type' => 'deal', 'related_id' => $h->f->deal, 'remind_email' => 1] + $remindCols('2026-01-15 09:00:00'));
    $mailer = $fakeMailer();
    $withScopes($a, ['deals.view' => null], function () use ($a, $mailer, $runEmails) {
        $runEmails($a, $mailer, '2026-01-15 05:30:00');
    });
    $mails = $sentTo($mailer, 'LiteTest TH T7 related');
    LiteAssert::same(2, count($mails));
    foreach ($mails as $mail) {
        if ($mail['to'] === (string) $a->member['email']) {
            LiteAssert::notContains($dealName, $mail['body'], 'member without deals.view: no deal name');
            LiteAssert::notContains('Deal', $mail['body']);
        } else {
            LiteAssert::contains($dealName, $mail['body'], 'admin sees the deal');
        }
    }

    $gone = $mk($a, 'LiteTest TH T7 gone', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $done = $mk($a, 'LiteTest TH T7 done', $aid, [$aid], $remindCols('2026-01-15 09:00:00') + ['remind_email' => 1]);
    $runEmails($a, $mailer, '2026-01-15 05:30:00', false); // notified; email waits for a mailer
    $a->pdo->exec("UPDATE tasks SET deleted_at = '2026-01-15 00:00:00' WHERE id = {$gone}");
    $a->pdo->exec("UPDATE tasks SET status = 'completed' WHERE id = {$done}");
    $a->pdo->exec("UPDATE task_notifications SET email_next_at = NULL WHERE task_id IN ({$gone}, {$done})");
    $runEmails($a, $mailer, '2026-01-15 06:00:00');
    LiteAssert::same([[], []], [$sentTo($mailer, 'LiteTest TH T7 gone'), $sentTo($mailer, 'LiteTest TH T7 done')]);
    LiteAssert::same(['skipped', 'skipped'], [$emailRows($a, $gone)[0]['email_status'], $emailRows($a, $done)[0]['email_status']]);
};

$tests['T7-E5 the email option: set from the dialog / edit form (hidden 0 + checkbox), only with a reminder; shown on the task page'] = function () use ($boot, $svc, $row, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    $dialog = $dialogHtml($a->request('GET', '/tasks')['body']);
    LiteAssert::contains('<input type="hidden" name="remind_email" value="0">', $dialog);
    LiteAssert::contains('<input type="checkbox" name="remind_email" value="1">', $dialog);
    $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T7 opt', 'remind_at' => '2026-06-01', 'remind_time' => '08:00', 'remind_email' => '1', 'return_to' => '/tasks']);
    $id = (int) $a->pdo->query("SELECT id FROM tasks WHERE title = 'LiteTest TH T7 opt'")->fetchColumn();
    LiteAssert::same(1, (int) $row($a, $id)['remind_email']);
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.remind_email_on'), $a->request('GET', "/tasks/{$id}")['body']);
    $svc($a)->update($a->admin, $id, ['title' => 'LiteTest TH T7 opt', 'remind_email' => '0']);
    LiteAssert::same(0, (int) $row($a, $id)['remind_email'], 'unchecked');
    $svc($a)->update($a->admin, $id, ['title' => 'LiteTest TH T7 opt', 'remind_email' => '1']);
    $svc($a)->update($a->admin, $id, ['title' => 'LiteTest TH T7 opt']);
    LiteAssert::same(1, (int) $row($a, $id)['remind_email'], 'absent: unchanged');
    $svc($a)->update($a->admin, $id, ['title' => 'LiteTest TH T7 opt', 'remind_at' => '', 'remind_time' => '']);
    LiteAssert::same(0, (int) $row($a, $id)['remind_email'], 'off with the reminder');
    $none = $svc($a)->create($a->admin, ['title' => 'LiteTest TH T7 opt 2', 'remind_email' => '1']);
    LiteAssert::same(0, (int) $none['remind_email'], 'no reminder: no email');
};

$tests['T7-R1 recurrence validation: type, interval 1–365, end date, needs a start or due date; stored with its anchor; cleared with ""'] = function () use ($boot, $svc, $invalid, $row) {
    $a = $boot();
    $a->as($a->admin);
    $s = $svc($a);
    $base = ['title' => 'LiteTest TH T7 rule bad', 'due_at' => '2026-03-10'];
    $invalid(fn () => $s->create($a->admin, ['title' => 'LiteTest TH T7 rule bad', 'recur_type' => 'daily']), 'recur_type', 'no date');
    $invalid(fn () => $s->create($a->admin, $base + ['recur_type' => 'yearly']), 'recur_type', 'unknown type');
    $invalid(fn () => $s->create($a->admin, $base + ['recur_type' => ['daily']]), 'recur_type', 'array');
    foreach (['0', '366', 'x', '1.5', '-1'] as $bad) {
        $invalid(fn () => $s->create($a->admin, $base + ['recur_type' => 'daily', 'recur_interval' => $bad]), 'recur_interval', $bad);
    }
    $invalid(fn () => $s->create($a->admin, $base + ['recur_type' => 'daily', 'recur_until' => '2026-02-30']), 'recur_until', 'invalid end');
    $invalid(fn () => $s->create($a->admin, $base + ['recur_type' => 'daily', 'recur_until' => '2026-03-09']), 'recur_until', 'end before the date');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM tasks WHERE title = 'LiteTest TH T7 rule bad'")->fetchColumn(), 'nothing written');

    $t = $s->create($a->admin, ['title' => 'LiteTest TH T7 rule', 'start_at' => '2026-03-02', 'due_at' => '2026-03-10', 'due_time' => '17:00', 'recur_type' => 'weekly', 'recur_interval' => '2', 'recur_until' => '2026-06-30']);
    LiteAssert::same(['weekly', 2, '2026-06-30', 1, '2026-03-02 00:00:00', 0], [$t['recur_type'], (int) $t['recur_interval'], $t['recur_until'], (int) $t['recur_active'], $t['recur_anchor'], (int) $t['recur_index']], 'anchored on the start');
    $id = (int) $t['id'];
    $a->pdo->exec("UPDATE tasks SET recur_index = 3 WHERE id = {$id}");
    $s->update($a->admin, $id, ['title' => 'LiteTest TH T7 rule', 'recur_type' => 'weekly', 'recur_interval' => '2', 'recur_until' => '2026-06-30', 'recur_active' => '0']);
    LiteAssert::same([0, 3], [(int) $row($a, $id)['recur_active'], (int) $row($a, $id)['recur_index']], 'paused; same rule and date: not re-based');
    $s->update($a->admin, $id, ['title' => 'LiteTest TH T7 rule', 'recur_type' => 'monthly', 'recur_interval' => '1', 'recur_active' => '1']);
    LiteAssert::same(['monthly', 1, 0], [$row($a, $id)['recur_type'], (int) $row($a, $id)['recur_active'] === 1 ? 1 : 0, (int) $row($a, $id)['recur_index']], 'a new rule re-bases');
    $invalid(fn () => $s->update($a->admin, $id, ['title' => 'LiteTest TH T7 rule', 'start_at' => '', 'due_at' => '']), 'recur_type', 'removing both dates of a recurring task');
    $s->update($a->admin, $id, ['title' => 'LiteTest TH T7 rule', 'recur_type' => '']);
    LiteAssert::same([null, 0, null], [$row($a, $id)['recur_type'], (int) $row($a, $id)['recur_active'], $row($a, $id)['recur_anchor']], 'cleared');
};

$tests['T7-R2 daily: the next occurrence is created when the head\'s date comes by the organization\'s clock; re-runs and a second worker add nothing'] = function () use ($boot, $mk, $row, $runRecurrence, $recurCols, $childOf, $childCount) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $head = $mk($a, 'LiteTest TH T7 daily', $aid, [$aid], ['due_at' => '2026-01-15 09:00:00', 'due_all_day' => 0] + $recurCols('daily', 1, '2026-01-15 09:00:00'));
    $runRecurrence($a, '2026-01-15 05:29:00'); // 08:59 Tehran
    LiteAssert::same(0, $childCount($a, $head), 'not before its time');
    $runRecurrence($a, '2026-01-15 05:30:00', 'UTC'); // a UTC organization: still 05:30
    LiteAssert::same(0, $childCount($a, $head), 'by the organization\'s clock');
    $runRecurrence($a, '2026-01-15 05:30:00');
    $child = $childOf($a, $head);
    LiteAssert::true($child !== false, 'created');
    LiteAssert::same(['2026-01-16 09:00:00', 0, 'pending', 1, $head, $head, (int) $a->orgId], [$child['due_at'], (int) $child['due_all_day'], $child['status'], (int) $child['recur_index'], (int) $child['series_id'], (int) $child['recur_parent_id'], (int) $child['organization_id']]);
    foreach (['2026-01-15 05:30:00', '2026-01-15 05:31:00', '2026-01-15 20:00:00'] as $again) {
        $runRecurrence($a, $again);
    }
    LiteAssert::same(1, $childCount($a, $head), 're-runs add nothing');
    LiteAssert::same(0, $childCount($a, (int) $child['id']), 'the new one waits for its own date');
    $repo = new \Modules\Sales\TaskRecurrenceRepository();
    LiteAssert::same(null, \App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $repo->lockHead($head)), 'no longer a head');
    LiteAssert::same(null, \App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $repo->insertOccurrence(['title' => 'LiteTest TH T7 dup', 'recur_parent_id' => $head])), 'a duplicate occurrence is refused by the database');
    $runRecurrence($a, '2026-01-16 05:30:00');
    $third = $childOf($a, (int) $child['id']);
    LiteAssert::same(['2026-01-17 09:00:00', 2, $head], [$third['due_at'], (int) $third['recur_index'], (int) $third['series_id']], 'the series goes on from the new head');
};

$tests['T7-R3 weekly every 2 weeks: start, due and reminder all move, all-day flags kept; the reminder is fresh (own occurrence, not fired), email setting kept'] = function () use ($boot, $mk, $runRecurrence, $recurCols, $childOf) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $head = $mk($a, 'LiteTest TH T7 weekly', $aid, [$aid], [
        'start_at' => '2026-01-05 00:00:00', 'start_all_day' => 1, 'due_at' => '2026-01-07 10:00:00', 'due_all_day' => 0,
        'remind_at' => '2026-01-06 08:00:00', 'remind_all_day' => 0, 'remind_seq' => 3, 'remind_fired_seq' => 3, 'remind_email' => 1,
    ] + $recurCols('weekly', 2, '2026-01-05 00:00:00'));
    $runRecurrence($a, '2026-01-04 20:29:00'); // 23:59 on the 4th, local
    LiteAssert::same(false, $childOf($a, $head));
    $runRecurrence($a, '2026-01-04 20:30:00'); // 00:00 on the 5th, local
    $c = $childOf($a, $head);
    LiteAssert::same(
        ['2026-01-19 00:00:00', 1, '2026-01-21 10:00:00', 0, '2026-01-20 08:00:00', 0, 1, 0, 1],
        [$c['start_at'], (int) $c['start_all_day'], $c['due_at'], (int) $c['due_all_day'], $c['remind_at'], (int) $c['remind_all_day'], (int) $c['remind_seq'], (int) $c['remind_fired_seq'], (int) $c['remind_email']]
    );
};

$tests['T7-R4 monthly keeps the day (Jan 31 → Feb 28 → Mar 31); every N months; missed occurrences are skipped, not created in bulk'] = function () use ($boot, $mk, $runRecurrence, $recurCols, $childOf, $count) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $at = fn (string $type, int $n, int $i, string $origin = '2026-01-31 00:00:00'): string => \Modules\Sales\Services\TaskRecurrenceService::anchorAt($origin, $type, $n, $i);
    LiteAssert::same(['2026-02-28 00:00:00', '2026-03-31 00:00:00', '2026-04-30 00:00:00', '2027-01-31 00:00:00'], [$at('monthly', 1, 1), $at('monthly', 1, 2), $at('monthly', 1, 3), $at('monthly', 12, 1)]);
    LiteAssert::same(['2028-02-29 09:30:00', '2026-02-14 09:30:00', '2026-01-16 09:30:00'], [$at('monthly', 1, 1, '2028-01-31 09:30:00'), $at('weekly', 2, 1, '2026-01-31 09:30:00') === '2026-02-14 09:30:00' ? '2026-02-14 09:30:00' : 'x', $at('daily', 3, 1, '2026-01-13 09:30:00')]);

    $head = $mk($a, 'LiteTest TH T7 monthly', $aid, [$aid], ['due_at' => '2026-01-31 00:00:00', 'due_all_day' => 1] + $recurCols('monthly', 1, '2026-01-31 00:00:00'));
    $runRecurrence($a, '2026-01-30 20:30:00'); // Jan 31, 00:00 local
    $feb = $childOf($a, $head);
    LiteAssert::same('2026-02-28 00:00:00', $feb['due_at']);
    $runRecurrence($a, '2026-02-27 20:30:00'); // Feb 28 local
    LiteAssert::same('2026-03-31 00:00:00', $childOf($a, (int) $feb['id'])['due_at'], 'back to the 31st');

    $late = $mk($a, 'LiteTest TH T7 missed', $aid, [$aid], ['due_at' => '2026-01-01 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-01 00:00:00'));
    $runRecurrence($a, '2026-01-10 06:00:00'); // the worker was down for days
    LiteAssert::same('2026-01-10 00:00:00', $childOf($a, $late)['due_at'], 'next = first on or after today');
    LiteAssert::same(2, $count($a, "SELECT COUNT(*) FROM tasks WHERE title = 'LiteTest TH T7 missed'"), 'no backlog');
};

$tests['T7-R5 end date stops the series; inactive, archived and deleted heads generate nothing; a completed head still repeats'] = function () use ($boot, $mk, $row, $runRecurrence, $recurCols, $childOf, $childCount) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $head = $mk($a, 'LiteTest TH T7 until', $aid, [$aid], ['due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00', '2026-01-16'));
    $runRecurrence($a, '2026-01-15 06:00:00');
    $last = $childOf($a, $head);
    LiteAssert::same('2026-01-16 00:00:00', $last['due_at'], 'the last one (on the end date)');
    $runRecurrence($a, '2026-01-16 06:00:00');
    LiteAssert::same(0, $childCount($a, (int) $last['id']), 'nothing after the end date');
    LiteAssert::same(0, (int) $row($a, (int) $last['id'])['recur_active'], 'the series is marked ended');

    $cases = [
        'inactive' => [],
        'archived' => ['archived_at' => '2026-01-10 00:00:00'],
        'deleted' => ['deleted_at' => '2026-01-10 00:00:00', 'deleted_by_user_id' => $aid],
    ];
    foreach ($cases as $label => $extra) {
        $id = $mk($a, "LiteTest TH T7 {$label}", $aid, [$aid], $extra + ['due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00', null, $label === 'inactive' ? 0 : 1));
        $runRecurrence($a, '2026-01-15 06:00:00');
        LiteAssert::same(0, $childCount($a, $id), $label);
    }
    $done = $mk($a, 'LiteTest TH T7 completed head', $aid, [$aid], ['status' => 'completed', 'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
    $runRecurrence($a, '2026-01-15 06:00:00');
    LiteAssert::same('pending', $childOf($a, $done)['status'], 'completing an occurrence does not stop the series; the new one is open');
};

$tests['T7-R6 the occurrence copies title, description, priority, labels and an unchecked checklist; keeps valid assignees and only relations the creator may still see'] = function () use ($boot, $h, $mk, $t5, $withScopes, $runRecurrence, $recurCols, $childOf, $labelSvc, $assignees, $items) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $x = $t5($a);
    $a->pdo->prepare("INSERT INTO users (organization_id, first_name, last_name, email, password_hash, status) VALUES (?, 'LiteTestGone', 'T7', ?, 'x', 'inactive')")
        ->execute([$a->orgId, 'litetest-th-t7-' . bin2hex(random_bytes(4)) . '@example.test']);
    $inactive = (int) $a->pdo->lastInsertId();
    $head = $mk($a, 'LiteTest TH T7 copy', $aid, [$mid, $inactive, $aid], ['description' => 'LiteTest T7 description', 'priority' => 'high', 'related_type' => 'deal', 'related_id' => $h->f->deal,
        'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
    $label = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest T7 label', 'color' => 'green'])['id'];
    $a->pdo->prepare('INSERT INTO task_label_links (task_id, label_id, organization_id) VALUES (?, ?, ?)')->execute([$head, $label, $a->orgId]);
    foreach ([['LiteTest T7 step 1', 1, '2026-01-14 10:00:00'], ['LiteTest T7 step 2', 0, null]] as $i => [$title, $done, $at]) {
        $a->pdo->prepare('INSERT INTO task_checklist_items (organization_id, task_id, title, is_done, completed_at, completed_by_user_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$a->orgId, $head, $title, $done, $at, $done ? $aid : null, $i]);
    }
    $runRecurrence($a, '2026-01-15 06:00:00');
    $c = $childOf($a, $head);
    $cid = (int) $c['id'];
    LiteAssert::same(['LiteTest TH T7 copy', 'LiteTest T7 description', 'high', 'deal', (int) $h->f->deal, $mid], [$c['title'], $c['description'], $c['priority'], $c['related_type'], (int) $c['related_id'], (int) $c['assigned_to_user_id']]);
    $both = [$aid, $mid];
    sort($both);
    LiteAssert::same($both, $assignees($a, $cid), 'the inactive assignee is dropped');
    LiteAssert::same([$label], array_map('intval', $a->pdo->query("SELECT label_id FROM task_label_links WHERE task_id = {$cid}")->fetchAll(\PDO::FETCH_COLUMN)), 'labels');
    $copied = $items($a, $cid);
    LiteAssert::same(['LiteTest T7 step 1', 'LiteTest T7 step 2'], array_column($copied, 'title'), 'checklist, in order');
    LiteAssert::same([[0, null, null], [0, null, null]], array_map(fn ($r) => [(int) $r['is_done'], $r['completed_at'], $r['completed_by_user_id']], $copied), 'all unchecked');
    LiteAssert::same(['LiteTest T7 step 1', 1], [$items($a, $head)[0]['title'], (int) $items($a, $head)[0]['is_done']], 'the original keeps its state');

    // The creator can no longer see the related project: the new occurrence has no relation.
    $withScopes($a, ['projects.view' => null], function () use ($a, $x, $mid, $mk, $runRecurrence, $recurCols, $childOf) {
        $p = $mk($a, 'LiteTest TH T7 hidden project', $mid, [$mid], ['related_type' => 'project', 'related_id' => $x->project, 'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
        $runRecurrence($a, '2026-01-15 06:00:00');
        $c = $childOf($a, $p);
        LiteAssert::same([null, null], [$c['related_type'], $c['related_id']], 'inaccessible relation not inherited');
    });
    $gone = $mk($a, 'LiteTest TH T7 missing deal', $aid, [$aid], ['related_type' => 'deal', 'related_id' => 999999999, 'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
    $runRecurrence($a, '2026-01-15 06:00:00');
    LiteAssert::same(null, $childOf($a, $gone)['related_type'], 'a deleted record is not linked again');
};

$tests['T7-R7 another organization\'s series is never generated by this one, never into this one, and never takes this organization\'s labels'] = function () use ($boot, $h, $mk, $runRecurrence, $recurCols, $childOf, $childCount, $labelSvc) {
    $a = $boot();
    if ($h->f->foreignUser === null) {
        throw new LiteSkip('needs a second organization');
    }
    $a->as($a->admin);
    $fu = (int) $h->f->foreignUser;
    $foreign = $mk($a, 'LiteTest TH T7 foreign series', $fu, [$fu], ['organization_id' => $a->otherOrgId, 'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
    $mine = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest T7 mine', 'color' => 'red'])['id'];
    $a->pdo->prepare('INSERT INTO task_label_links (task_id, label_id, organization_id) VALUES (?, ?, ?)')->execute([$foreign, $mine, $a->orgId]); // a planted cross link
    $runRecurrence($a, '2026-01-15 06:00:00');
    LiteAssert::same(0, $childCount($a, $foreign), 'this organization\'s run does not touch it');
    $runRecurrence($a, '2026-01-15 06:00:00', 'Asia/Tehran', $a->otherOrgId);
    $c = $childOf($a, $foreign);
    LiteAssert::same((int) $a->otherOrgId, (int) $c['organization_id'], 'created in its own organization');
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM task_label_links WHERE task_id = ' . (int) $c['id'])->fetchColumn(), 'no label of another organization');
    $a->as($a->admin);
    LiteAssert::same('notfound', $a->outcome('GET', '/tasks/' . (int) $c['id']), 'invisible here');
};

$tests['T7-R8 permissions: editing the rule needs tasks.edit on the task; series links only to visible occurrences; UI shows the rule in dialog, row, task page (fa too); keys in five languages'] = function () use ($boot, $mk, $svc, $member, $withScopes, $runRecurrence, $recurCols, $childOf, $rowHtml, $dialogHtml, $row) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $tr = fn (string $key, array $r = []): string => \App\Infrastructure\Theme\t($key, $r);
    $dialog = $dialogHtml($a->request('GET', '/tasks')['body']);
    LiteAssert::contains('<select id="task-new-recur" name="recur_type">', $dialog);
    LiteAssert::contains('name="recur_interval" min="1" max="365" value="1"', $dialog);
    LiteAssert::contains('class="crm-datepicker" id="task-new-recur-until" name="recur_until"', $dialog);
    $r = $a->request('POST', '/tasks', [], ['title' => 'LiteTest TH T7 dialog', 'recur_type' => 'daily', 'recur_interval' => '3', 'return_to' => '/tasks']);
    LiteAssert::same(422, $r['status'], 'no date');
    LiteAssert::contains('<option value="daily" selected>', $dialogHtml($r['body']), 'kept');
    LiteAssert::contains($tr('tasks.error.recur_needs_date'), $dialogHtml($r['body']));

    $head = $mk($a, 'LiteTest TH T7 ui', $aid, [$aid], ['due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('weekly', 2, '2026-01-15 00:00:00', '2026-12-31'));
    $html = $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T7 ui'])['body'], $head);
    LiteAssert::contains('<span class="task-recurrence"><svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#i-refresh"></use></svg>' . $tr('tasks.recur.every_weekly', ['n' => '2']), $html, 'rule on the row');
    $runRecurrence($a, '2026-01-15 06:00:00');
    $child = (int) $childOf($a, $head)['id'];
    LiteAssert::contains('<a href="/tasks/' . $child . '">', $a->request('GET', "/tasks/{$head}")['body'], 'next occurrence link');
    LiteAssert::contains('<a href="/tasks/' . $head . '">', $a->request('GET', "/tasks/{$child}")['body'], 'previous occurrence link');
    $show = $a->request('GET', "/tasks/{$child}")['body'];
    LiteAssert::contains('<option value="weekly" selected>', $show, 'edit form');
    LiteAssert::contains('<input type="checkbox" name="recur_active" value="1" checked>', $show);

    $a->as($member($a));
    LiteAssert::same('forbidden', $a->outcome('POST', "/tasks/{$child}", [], ['title' => 'LiteTest TH T7 ui', 'recur_type' => '']), 'member: tasks.edit is own only');
    LiteAssert::same('weekly', $row($a, $child)['recur_type']);
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $member, $mk, $mid, $aid, $runRecurrence, $recurCols, $childOf) {
        $mineHead = $mk($a, 'LiteTest TH T7 own', $aid, [$aid], ['due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
        $runRecurrence($a, '2026-01-15 06:00:00');
        $next = (int) $childOf($a, $mineHead)['id'];
        $a->pdo->exec("DELETE FROM task_assignees WHERE task_id = {$next}");
        $a->pdo->exec("INSERT INTO task_assignees (task_id, user_id) VALUES ({$next}, {$mid})");
        $a->pdo->exec("UPDATE tasks SET assigned_to_user_id = {$mid}, created_by_user_id = {$mid} WHERE id = {$next}");
        $a->as($member($a));
        LiteAssert::notContains('<a href="/tasks/' . $mineHead . '">', $a->request('GET', "/tasks/{$next}")['body'], 'no link to an occurrence the member may not see');
    });

    $a->as($a->admin);
    $a->locale('fa');
    try {
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.recur.every_weekly', ['n' => '۲']), $a->request('GET', "/tasks/{$child}")['body'], 'fa, Persian digits');
    } finally {
        $a->locale('en');
    }
    $keys = array_keys(require dirname(__DIR__, 2) . '/lang/en.php');
    $t7 = array_values(array_filter($keys, fn (string $k) => str_starts_with($k, 'tasks.recur.') || str_starts_with($k, 'tasks.email.')
        || in_array($k, ['tasks.remind_email', 'tasks.remind_email_on', 'tasks.error.invalid_recurrence', 'tasks.error.invalid_recur_interval', 'tasks.error.invalid_recur_until', 'tasks.error.recur_needs_date', 'tasks.error.recur_until_before'], true)));
    LiteAssert::same(30, count($t7));
    foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        foreach ($t7 as $key) {
            LiteAssert::true(isset($strings[$key]) && trim($strings[$key]) !== '', "{$lang}: {$key}");
        }
    }
};

$tests['T7-R9 each occurrence has its own reminder: the head\'s fires once, the new one once, never across occurrences; the cron script runs recurrence before reminders'] = function () use ($boot, $mk, $runRecurrence, $runReminders, $recurCols, $remindCols, $childOf, $reminderRows) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $head = $mk($a, 'LiteTest TH T7 reminders', $aid, [$aid], ['due_at' => '2026-01-15 10:00:00', 'due_all_day' => 0] + $remindCols('2026-01-15 09:00:00') + $recurCols('daily', 1, '2026-01-15 10:00:00'));
    $runReminders($a, '2026-01-15 05:30:00'); // 09:00 local: head's reminder
    $runRecurrence($a, '2026-01-15 06:30:00'); // 10:00 local: next occurrence
    $runReminders($a, '2026-01-15 06:30:00');
    $c = $childOf($a, $head);
    LiteAssert::same(['2026-01-16 09:00:00', 1, 0], [$c['remind_at'], (int) $c['remind_seq'], (int) $c['remind_fired_seq']], 'fresh reminder, not due yet');
    LiteAssert::same([], $reminderRows($a, (int) $c['id']));
    $runReminders($a, '2026-01-16 05:30:00');
    $runReminders($a, '2026-01-16 05:31:00');
    LiteAssert::same(1, count($reminderRows($a, (int) $c['id'])), 'the new occurrence: once');
    LiteAssert::same(1, count($reminderRows($a, $head)), 'the head: still once');
    $script = file_get_contents(dirname(__DIR__, 2) . '/modules/Sales/bin/task-reminders.php');
    LiteAssert::true(strpos($script, 'TaskRecurrenceService::class)->runAll()') < strpos($script, '$service->runAll()'), 'recurrence first');
    LiteAssert::true($a->container()->has(\Modules\Sales\Services\TaskRecurrenceService::class), 'bound for the script');
    $up = explode('public function down', file_get_contents(dirname(__DIR__, 2) . '/modules/Sales/Migrations/0012_add_task_email_reminders_and_recurrence.php'))[0];
    LiteAssert::false((bool) preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $up), '0012 up() is additive');
};

// ---- T8: custom task statuses -----------------------------------------------------------------------
$statusSvc = fn (LocalApp $a): \Modules\Sales\Services\TaskStatusService => $svc($a)->statuses();
/** The organization's original status for a legacy value. */
$systemStatus = fn (LocalApp $a, string $key, ?int $orgId = null): array => $a->pdo->query('SELECT * FROM task_statuses WHERE organization_id = ' . ($orgId ?? $a->orgId) . ' AND system_key = ' . $a->pdo->quote($key))->fetch(\PDO::FETCH_ASSOC);
$newStatus = fn (LocalApp $a, string $name, string $category, string $color = 'blue'): int => (int) $statusSvc($a)->create($a->admin, ['name' => $name, 'color' => $color, 'category' => $category])['id'];
$statusRow = fn (LocalApp $a, int $id): array|false => $a->pdo->query("SELECT * FROM task_statuses WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);

$tests['T8-1 every organization has the six original statuses (translated until renamed, mapped to fixed categories); one without any gets them on first use'] = function () use ($boot, $statusSvc) {
    $a = $boot();
    $a->as($a->admin);
    $rows = $statusSvc($a)->all();
    $originals = array_values(array_filter($rows, fn (array $s) => $s['system_key'] !== null));
    LiteAssert::same(['pending', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled'], array_column($originals, 'system_key'));
    LiteAssert::same(['open', 'in_progress', 'in_progress', 'completed', 'cancelled', 'cancelled'], array_column($originals, 'category'));
    LiteAssert::same(\App\Infrastructure\Theme\t('tasks.status.on_hold'), $originals[2]['label'], 'unnamed: translated');
    if ($a->otherOrgId !== null) {
        $a->pdo->exec('UPDATE tasks SET status_id = NULL WHERE organization_id = ' . $a->otherOrgId);
        $a->pdo->exec('DELETE FROM task_statuses WHERE organization_id = ' . $a->otherOrgId);
        $created = \App\Infrastructure\Repositories\OrganizationContext::runAs($a->otherOrgId, fn () => $statusSvc($a)->all());
        LiteAssert::same(6, count($created), 'a new organization gets the originals');
        LiteAssert::same([$a->otherOrgId], array_values(array_unique(array_map('intval', $a->pdo->query('SELECT organization_id FROM task_statuses WHERE id IN (' . implode(',', array_column($created, 'id')) . ')')->fetchAll(\PDO::FETCH_COLUMN)))), 'in its own organization');
    }
};

$tests['T8-2 create, rename, recolor, activate / deactivate; names validated (blank, too long, duplicate), colors only from the palette, category fixed and valid'] = function () use ($boot, $statusSvc, $newStatus, $statusRow, $systemStatus, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $s = $statusSvc($a);
    $id = $newStatus($a, 'LiteTest Waiting for client', 'in_progress', 'orange');
    LiteAssert::same(['LiteTest Waiting for client', 'orange', 'in_progress', 1, null], array_values(array_intersect_key($statusRow($a, $id), array_flip(['name', 'color', 'category', 'is_active', 'system_key']))));
    $invalid(fn () => $s->create($a->admin, ['name' => '  ', 'color' => 'blue', 'category' => 'open']), 'status_name', 'blank');
    $invalid(fn () => $s->create($a->admin, ['name' => str_repeat('x', 51), 'color' => 'blue', 'category' => 'open']), 'status_name', 'too long');
    $invalid(fn () => $s->create($a->admin, ['name' => 'litetest WAITING for client', 'color' => 'blue', 'category' => 'open']), 'status_name', 'duplicate (case)');
    $invalid(fn () => $s->create($a->admin, ['name' => \App\Infrastructure\Theme\t('tasks.status.pending'), 'color' => 'blue', 'category' => 'open']), 'status_name', 'an original\'s shown name');
    foreach (['purple', '#ff0000', 'red; background:url(x)', ''] as $bad) {
        $invalid(fn () => $s->create($a->admin, ['name' => 'LiteTest color', 'color' => $bad, 'category' => 'open']), 'status_color', $bad);
    }
    $invalid(fn () => $s->create($a->admin, ['name' => 'LiteTest cat', 'color' => 'blue', 'category' => 'done']), 'status_category', 'category');
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM task_statuses WHERE name IN ('LiteTest color', 'LiteTest cat')")->fetchColumn(), 'nothing written');

    $s->update($a->admin, $id, ['name' => 'LiteTest Waiting', 'color' => 'sky', 'category' => 'completed']);
    LiteAssert::same(['LiteTest Waiting', 'sky', 'in_progress'], [$statusRow($a, $id)['name'], $statusRow($a, $id)['color'], $statusRow($a, $id)['category']], 'renamed + recolored; the category never changes');
    $pending = $systemStatus($a, 'pending');
    $s->update($a->admin, (int) $pending['id'], ['name' => 'LiteTest To do', 'color' => 'gray']);
    LiteAssert::same('LiteTest To do', $s->find((int) $pending['id'])['label'], 'an original renamed');
    $s->update($a->admin, (int) $pending['id'], ['name' => '', 'color' => 'gray']);
    LiteAssert::same(\App\Infrastructure\Theme\t('tasks.status.pending'), $s->find((int) $pending['id'])['label'], 'empty name: translated again');
    $invalid(fn () => $s->update($a->admin, $id, ['name' => '', 'color' => 'sky']), 'status_name', 'a custom status needs a name');

    $s->update($a->admin, $id, ['name' => 'LiteTest Waiting', 'color' => 'sky', 'is_active' => '0']);
    LiteAssert::same(0, (int) $statusRow($a, $id)['is_active'], 'deactivated');
    $s->update($a->admin, $id, ['name' => 'LiteTest Waiting', 'color' => 'sky', 'is_active' => '1']);
    LiteAssert::same(1, (int) $statusRow($a, $id)['is_active'], 'active again');
    $invalid(fn () => $s->update($a->admin, (int) $pending['id'], ['name' => '', 'color' => 'gray', 'is_active' => '0']), 'status', 'the last active open status');
    $invalid(fn () => $s->update($a->admin, (int) $systemStatus($a, 'completed')['id'], ['name' => '', 'color' => 'green', 'is_active' => '0']), 'status', 'the last active completed status');
    $other = $newStatus($a, 'LiteTest Backlog', 'open', 'gray');
    $s->update($a->admin, (int) $pending['id'], ['name' => '', 'color' => 'gray', 'is_active' => '0']);
    LiteAssert::same(0, (int) $statusRow($a, (int) $pending['id'])['is_active'], 'fine once another open status is active');
    $s->update($a->admin, (int) $pending['id'], ['name' => '', 'color' => 'gray', 'is_active' => '1']);
    LiteAssert::true($other > 0);
};

$tests['T8-3 reorder: up / down swap neighbours (normalised positions); the first cannot go up, the last cannot go down'] = function () use ($boot, $statusSvc, $newStatus) {
    $a = $boot();
    $a->as($a->admin);
    $s = $statusSvc($a);
    $id = $newStatus($a, 'LiteTest Reorder', 'open');
    $order = fn (): array => array_map(fn (array $r) => (int) $r['id'], $s->all());
    $before = $order();
    LiteAssert::same($id, end($before), 'new statuses go last');
    $s->move($a->admin, $id, 'up');
    $after = $order();
    LiteAssert::same($id, $after[count($after) - 2], 'one up');
    LiteAssert::same($before[count($before) - 2], end($after), 'swapped with its neighbour');
    $s->move($a->admin, $id, 'down');
    LiteAssert::same($before, $order(), 'back');
    $s->move($a->admin, $id, 'down');
    LiteAssert::same($before, $order(), 'the last cannot go down');
    $s->move($a->admin, $before[0], 'up');
    LiteAssert::same($before, $order(), 'the first cannot go up');
    $s->move($a->admin, $before[0], 'sideways');
    LiteAssert::same($before, $order(), 'unknown direction: nothing');
};

$tests['T8-4 delete: an unused status goes; one in use only by moving its tasks (incl. untouched legacy ones) to an active status of the same category; never deletes tasks'] = function () use ($boot, $mk, $row, $count, $statusSvc, $newStatus, $statusRow, $systemStatus, $invalid) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $s = $statusSvc($a);
    $unused = $newStatus($a, 'LiteTest Unused', 'cancelled');
    $s->delete($a->admin, $unused);
    LiteAssert::same(false, $statusRow($a, $unused), 'deleted');

    $onHold = $systemStatus($a, 'on_hold');
    $legacy = $mk($a, 'LiteTest TH T8 legacy on hold', $aid, [$aid], ['status' => 'on_hold']);
    $chosen = $mk($a, 'LiteTest TH T8 chosen on hold', $aid, [$aid], ['status' => 'on_hold', 'status_id' => (int) $onHold['id']]);
    $tasksBefore = $count($a, 'SELECT COUNT(*) FROM tasks WHERE organization_id = ' . $a->orgId);
    LiteAssert::true($s->usage($s->find((int) $onHold['id'])) >= 2, 'in use (legacy + chosen)');
    $invalid(fn () => $s->delete($a->admin, (int) $onHold['id']), 'status_replacement', 'no replacement');
    $invalid(fn () => $s->delete($a->admin, (int) $onHold['id'], (string) $systemStatus($a, 'pending')['id']), 'status_replacement', 'another category');
    $invalid(fn () => $s->delete($a->admin, (int) $onHold['id'], (string) $onHold['id']), 'status_replacement', 'itself');
    $inactive = $newStatus($a, 'LiteTest Inactive progress', 'in_progress');
    $s->update($a->admin, $inactive, ['name' => 'LiteTest Inactive progress', 'color' => 'blue', 'is_active' => '0']);
    $invalid(fn () => $s->delete($a->admin, (int) $onHold['id'], (string) $inactive), 'status_replacement', 'an inactive one');
    LiteAssert::true($statusRow($a, (int) $onHold['id']) !== false, 'still there');

    $target = $systemStatus($a, 'in_progress');
    $s->delete($a->admin, (int) $onHold['id'], (string) $target['id']);
    LiteAssert::same(false, $statusRow($a, (int) $onHold['id']));
    foreach ([$legacy, $chosen] as $id) {
        LiteAssert::same([(int) $target['id'], 'in_progress'], [(int) $row($a, $id)['status_id'], $row($a, $id)['status']], "task {$id} moved");
    }
    LiteAssert::same($tasksBefore, $count($a, 'SELECT COUNT(*) FROM tasks WHERE organization_id = ' . $a->orgId), 'no task deleted');
    $a->pdo->exec("UPDATE task_statuses SET is_active = 0 WHERE organization_id = {$a->orgId} AND category = 'open' AND system_key IS NULL");
    $invalid(fn () => $s->delete($a->admin, (int) $systemStatus($a, 'pending')['id']), 'status', 'the last active open status');
    $a->pdo->exec("UPDATE task_statuses SET is_active = 1 WHERE organization_id = {$a->orgId} AND category = 'open'");
    $a->pdo->exec("INSERT INTO task_statuses (organization_id, color, category, sort_order, is_active, system_key) VALUES ({$a->orgId}, 'orange', 'in_progress', 30, 1, 'on_hold')"); // restored for the next tests
};

$tests['T8-5 security: managing needs organization.edit; another organization\'s statuses can never be seen, managed, assigned or filtered by; CSRF on every status route'] = function () use ($boot, $h, $mk, $svc, $member, $setScopes, $statusSvc, $newStatus, $statusRow, $systemStatus, $invalid, $listedIds) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $id = $newStatus($a, 'LiteTest Secure', 'in_progress');
    LiteAssert::same('ok', $a->outcome('GET', '/tasks/statuses'), 'admin may manage');
    $setScopes($a, ['organization.edit' => null]);
    $a->as($member($a));
    LiteAssert::false($statusSvc($a)->canManage($member($a)));
    foreach ([['GET', '/tasks/statuses', []], ['POST', '/tasks/statuses', ['name' => 'LiteTest Sneaky', 'color' => 'red', 'category' => 'open']],
        ['POST', "/tasks/statuses/{$id}", ['name' => 'LiteTest Hijack', 'color' => 'red']], ['POST', "/tasks/statuses/{$id}/move", ['direction' => 'up']],
        ['POST', "/tasks/statuses/{$id}/delete", []]] as [$method, $path, $body]) {
        LiteAssert::same('forbidden', $a->outcome($method, $path, [], $body), "member: {$method} {$path}");
    }
    LiteAssert::same('LiteTest Secure', $statusRow($a, $id)['name'], 'unchanged');
    LiteAssert::notContains('href="/tasks/statuses"', $a->request('GET', '/tasks')['body'], 'no manage button');
    $a->as($a->admin);
    foreach (["/tasks/statuses", "/tasks/statuses/{$id}", "/tasks/statuses/{$id}/move", "/tasks/statuses/{$id}/delete"] as $path) {
        LiteAssert::same('csrf', $a->outcome('POST', $path, [], ['_csrf' => 'wrong', 'name' => 'x', 'color' => 'red', 'category' => 'open']), "CSRF {$path}");
    }

    if ($a->otherOrgId !== null) {
        $foreign = (int) $systemStatus($a, 'in_progress', $a->otherOrgId)['id'];
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/statuses/{$foreign}", [], ['name' => 'LiteTest Hijack', 'color' => 'red']), 'another organization\'s status');
        LiteAssert::same('notfound', $a->outcome('POST', "/tasks/statuses/{$foreign}/delete"), 'not deletable');
        LiteAssert::false(in_array($foreign, array_map(fn ($s) => (int) $s['id'], $statusSvc($a)->all()), true), 'never listed');
        $t = $mk($a, 'LiteTest TH T8 foreign status', $aid, [$aid]);
        $invalid(fn () => $svc($a)->changeStatusTo($a->admin, $t, $foreign), 'status', 'never assignable');
        $invalid(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest TH T8 x', 'status_id' => (string) $foreign]), 'status_id', 'not on create');
        $invalid(fn () => $svc($a)->update($a->admin, $t, ['title' => 'LiteTest TH T8 foreign status', 'status_id' => (string) $foreign]), 'status_id', 'not on edit');
        $page = $a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 foreign status', 'status_id' => (string) $foreign])['body'];
        LiteAssert::same([$t], $listedIds($page), 'a foreign status id filters nothing');
        LiteAssert::notContains('status_id=' . $foreign, $page);
    }
};

$tests['T8-6 tasks take custom statuses: create (default / chosen), the row menu, the edit form; lifecycle follows the category (completion, reopen with tasks.reopen, workflow)'] = function () use ($boot, $mk, $svc, $row, $member, $withScopes, $statusSvc, $newStatus, $systemStatus, $invalid, $rowHtml, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $waiting = $newStatus($a, 'LiteTest Waiting for client', 'in_progress', 'orange');
    $finished = $newStatus($a, 'LiteTest Finished', 'completed', 'green');
    $pending = (int) $systemStatus($a, 'pending')['id'];

    $plain = $svc($a)->create($a->admin, ['title' => 'LiteTest TH T8 default']);
    LiteAssert::same([$pending, 'pending'], [(int) $plain['status_id'], $plain['status']], 'the first open status');
    $chosen = $svc($a)->create($a->admin, ['title' => 'LiteTest TH T8 chosen', 'status_id' => (string) $waiting]);
    LiteAssert::same([$waiting, 'in_progress'], [(int) $chosen['status_id'], $chosen['status']], 'chosen at create');
    $invalid(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest TH T8 bad', 'status_id' => (string) $finished]), 'status_id', 'not straight to completed');
    $statusSvc($a)->update($a->admin, $waiting, ['name' => 'LiteTest Waiting for client', 'color' => 'orange', 'is_active' => '0']);
    $invalid(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest TH T8 bad', 'status_id' => (string) $waiting]), 'status_id', 'not an inactive one');
    $statusSvc($a)->update($a->admin, $waiting, ['name' => 'LiteTest Waiting for client', 'color' => 'orange', 'is_active' => '1']);
    $dialog = $dialogHtml($a->request('GET', '/tasks')['body']);
    LiteAssert::contains('<select id="task-new-status" name="status_id">', $dialog);
    LiteAssert::contains('>LiteTest Waiting for client</option>', $dialog);
    LiteAssert::notContains('>LiteTest Finished</option>', $dialog, 'only open / in progress at create');
    $r = $a->request('POST', '/tasks', [], ['title' => '', 'status_id' => (string) $waiting, 'return_to' => '/tasks']);
    LiteAssert::contains('<option value="' . $waiting . '" selected>', $dialogHtml($r['body']), 'kept after a validation error');

    $t = (int) $plain['id'];
    $html = $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 default'])['body'], $t);
    LiteAssert::contains('<input type="hidden" name="status_id" value="' . $waiting . '">', $html, 'row menu offers custom statuses');
    LiteAssert::contains('LiteTest Waiting for client</button>', $html);
    $a->request('POST', "/tasks/{$t}/status", [], ['status_id' => (string) $waiting, 'return_to' => '/tasks']);
    LiteAssert::same([$waiting, 'in_progress', null], [(int) $row($a, $t)['status_id'], $row($a, $t)['status'], $row($a, $t)['completed_at']]);
    LiteAssert::contains('<span class="badge task-status badge--warning">LiteTest Waiting for client</span>', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 default'])['body'], $t), 'badge in its color');
    $svc($a)->changeStatusTo($a->admin, $t, $finished);
    LiteAssert::same([$finished, 'completed'], [(int) $row($a, $t)['status_id'], $row($a, $t)['status']], 'the category decides: completed');
    LiteAssert::true($row($a, $t)['completed_at'] !== null, 'completion behavior kept');
    $svc($a)->changeStatusTo($a->admin, $t, (int) $systemStatus($a, 'completed')['id']);
    LiteAssert::same('completed', $row($a, $t)['status'], 'between completed statuses: a relabel');

    $a->pdo->exec("UPDATE tasks SET created_by_user_id = {$mid}, assigned_to_user_id = {$mid} WHERE id = {$t}");
    $a->pdo->exec("INSERT INTO task_assignees (task_id, user_id) VALUES ({$t}, {$mid})");
    $a->as($member($a));
    try {
        $svc($a)->changeStatusTo($member($a), $t, $pending);
        throw new \RuntimeException('reopened without tasks.reopen');
    } catch (AuthorizationException) {
    }
    LiteAssert::same('completed', $row($a, $t)['status'], 'reopen still needs tasks.reopen');
    $withScopes($a, ['tasks.reopen' => 'own'], function () use ($a, $t, $svc, $member, $row, $pending) {
        $a->as($member($a));
        $svc($a)->toggleComplete($member($a), $t);
        LiteAssert::same([$pending, 'pending', null], [(int) $row($a, $t)['status_id'], $row($a, $t)['status'], $row($a, $t)['completed_at']], 'toggle reopens to the first open status');
        $svc($a)->toggleComplete($member($a), $t);
        LiteAssert::same('completed', $row($a, $t)['status'], 'and completes again');
    });
    $a->as($a->admin);
    $rej = $mk($a, 'LiteTest TH T8 rejected', $aid, [$aid], ['status' => 'rejected']);
    $invalid(fn () => $svc($a)->changeStatusTo($a->admin, $rej, (int) $systemStatus($a, 'cancelled')['id']), 'status', 'a closed task can only be reopened (workflow kept)');

    $edit = $mk($a, 'LiteTest TH T8 edit', $aid, [$aid]);
    $show = $a->request('GET', "/tasks/{$edit}")['body'];
    LiteAssert::contains('<select id="status_id" name="status_id">', $show);
    $svc($a)->update($a->admin, $edit, ['title' => 'LiteTest TH T8 edit', 'status_id' => (string) $waiting]);
    LiteAssert::same($waiting, (int) $row($a, $edit)['status_id'], 'edit form');
    $statusSvc($a)->update($a->admin, $waiting, ['name' => 'LiteTest Waiting for client', 'color' => 'orange', 'is_active' => '0']);
    $show = $a->request('GET', "/tasks/{$edit}")['body'];
    LiteAssert::contains('<option value="' . $waiting . '" selected>LiteTest Waiting for client (' . \App\Infrastructure\Theme\t('tasks.status_inactive') . ')</option>', $show, 'an inactive status stays on its tasks, marked');
    $svc($a)->update($a->admin, $edit, ['title' => 'LiteTest TH T8 edit renamed', 'status_id' => (string) $waiting]);
    LiteAssert::same(['LiteTest TH T8 edit renamed', $waiting], [$row($a, $edit)['title'], (int) $row($a, $edit)['status_id']], 'saving with its inactive status is fine');
    $fresh = $mk($a, 'LiteTest TH T8 fresh', $aid, [$aid]);
    $invalid(fn () => $svc($a)->changeStatusTo($a->admin, $fresh, $waiting), 'status', 'an inactive status cannot be chosen');
};

$tests['T8-7 legacy tasks (no status_id) show their status through the organization\'s original (renamed too); the legacy status API keeps status_id in step'] = function () use ($boot, $mk, $svc, $row, $statusSvc, $systemStatus, $rowHtml) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $t = $mk($a, 'LiteTest TH T8 untouched', $aid, [$aid], ['status' => 'on_hold']);
    LiteAssert::same(null, $row($a, $t)['status_id']);
    $onHold = $systemStatus($a, 'on_hold');
    LiteAssert::contains('<span class="badge task-status badge--warning">' . \App\Infrastructure\Theme\t('tasks.status.on_hold') . '</span>', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 untouched'])['body'], $t));
    $statusSvc($a)->update($a->admin, (int) $onHold['id'], ['name' => 'LiteTest Parked', 'color' => 'red']);
    LiteAssert::contains('<span class="badge task-status badge--danger">LiteTest Parked</span>', $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 untouched'])['body'], $t), 'renamed / recolored for untouched tasks too');
    LiteAssert::same(null, $row($a, $t)['status_id'], 'no task row rewritten');
    $svc($a)->changeStatus($a->admin, $t, 'in_progress');
    LiteAssert::same([(int) $systemStatus($a, 'in_progress')['id'], 'in_progress'], [(int) $row($a, $t)['status_id'], $row($a, $t)['status']], 'legacy API: status_id follows');
    $a->pdo->exec("UPDATE tasks SET status_id = NULL, status = 'pending' WHERE id = {$t}");
    $a->pdo->exec('UPDATE tasks SET status_id = NULL WHERE organization_id = ' . $a->orgId . " AND status = 'pending'");
    $a->pdo->exec('DELETE FROM task_statuses WHERE organization_id = ' . $a->orgId . " AND system_key = 'pending'");
    $html = $rowHtml($a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 untouched'])['body'], $t);
    LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.status.pending'), $html, 'no status for it any more: the legacy label, as before');
    $a->pdo->exec("INSERT INTO task_statuses (organization_id, color, category, sort_order, is_active, system_key) VALUES ({$a->orgId}, 'gray', 'open', 10, 1, 'pending')"); // restored for the next tests
};

$tests['T8-8 archive / restore: an archived-category status archives (needs tasks.archive) and restore returns the task to its own status; plain archive keeps the status'] = function () use ($boot, $mk, $svc, $row, $member, $withScopes, $newStatus, $systemStatus) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $shelved = $newStatus($a, 'LiteTest Shelved', 'archived', 'gray');
    $t = $mk($a, 'LiteTest TH T8 shelve', $aid, [$aid], ['status' => 'in_progress']);
    $svc($a)->changeStatusTo($a->admin, $t, $shelved);
    LiteAssert::same([$shelved, 'in_progress'], [(int) $row($a, $t)['status_id'], $row($a, $t)['status']]);
    LiteAssert::true($row($a, $t)['archived_at'] !== null, 'archived');
    $svc($a)->unarchive($a->admin, $t);
    LiteAssert::same([null, null, 'in_progress'], [$row($a, $t)['archived_at'], $row($a, $t)['status_id'], $row($a, $t)['status']], 'restored to its own status');

    $waiting = $newStatus($a, 'LiteTest Waiting T8-8', 'in_progress');
    $k = $mk($a, 'LiteTest TH T8 keep', $aid, [$aid], ['status' => 'in_progress', 'status_id' => $waiting]);
    $svc($a)->archive($a->admin, $k);
    $svc($a)->unarchive($a->admin, $k);
    LiteAssert::same([$waiting, null], [(int) $row($a, $k)['status_id'], $row($a, $k)['archived_at']], 'archive / restore keep a normal status');

    $mid = (int) $a->member['id'];
    $m = $mk($a, 'LiteTest TH T8 member shelve', $mid, [$mid]);
    $withScopes($a, ['tasks.archive' => null], function () use ($a, $m, $svc, $member, $row, $shelved) {
        $a->as($member($a));
        try {
            $svc($a)->changeStatusTo($member($a), $m, $shelved);
            throw new \RuntimeException('archived without tasks.archive');
        } catch (AuthorizationException) {
        }
        LiteAssert::same(null, $row($a, $m)['archived_at']);
        $options = array_map(fn ($o) => (int) $o['id'], $svc($a)->actionsFor($member($a), [$row($a, $m)])[$m]['status_options']);
        LiteAssert::false(in_array($shelved, $options, true), 'not offered either');
    });
};

$tests['T8-9 reminders and recurrence follow the lifecycle: a custom completed status gets no reminder, a custom in-progress one does; new occurrences start in the first open status'] = function () use ($boot, $mk, $svc, $row, $statusSvc, $newStatus, $runReminders, $reminderRows, $remindCols, $runRecurrence, $recurCols, $childOf) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $waiting = $newStatus($a, 'LiteTest Waiting T8-9', 'in_progress');
    $finished = $newStatus($a, 'LiteTest Finished T8-9', 'completed');
    $open = $mk($a, 'LiteTest TH T8 remind open', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $done = $mk($a, 'LiteTest TH T8 remind done', $aid, [$aid], $remindCols('2026-01-15 09:00:00'));
    $svc($a)->changeStatusTo($a->admin, $open, $waiting);
    $svc($a)->changeStatusTo($a->admin, $done, $finished);
    $runReminders($a, '2026-01-15 05:30:00');
    LiteAssert::same(1, count($reminderRows($a, $open)), 'in progress: reminded');
    LiteAssert::same([], $reminderRows($a, $done), 'completed: not reminded');

    $backlog = $newStatus($a, 'LiteTest Backlog T8-9', 'open', 'gray');
    for ($i = count($statusSvc($a)->all()); $i > 0; $i--) {
        $statusSvc($a)->move($a->admin, $backlog, 'up');
    }
    LiteAssert::same($backlog, (int) $statusSvc($a)->all()[0]['id'], 'moved first');
    $head = $mk($a, 'LiteTest TH T8 series', $aid, [$aid], ['status' => 'completed', 'status_id' => $finished, 'due_at' => '2026-01-15 00:00:00', 'due_all_day' => 1] + $recurCols('daily', 1, '2026-01-15 00:00:00'));
    $runRecurrence($a, '2026-01-15 06:00:00');
    $c = $childOf($a, $head);
    LiteAssert::same([$backlog, 'pending'], [(int) $c['status_id'], $c['status']], 'the first open status, not the head\'s');
};

$tests['T8-10 list: filter by a custom status (incl. untouched legacy tasks of an original); search + filter + pages keep it; manage button; deal page badge; fa too; keys in five languages'] = function () use ($boot, $h, $mk, $svc, $newStatus, $systemStatus, $listedIds, $statusSvc) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $waiting = $newStatus($a, 'LiteTest Waiting T8-10', 'in_progress');
    $onHold = (int) $systemStatus($a, 'on_hold')['id'];
    $w = $mk($a, 'LiteTest TH T8 filter waiting', $aid, [$aid], ['status' => 'in_progress', 'status_id' => $waiting]);
    $legacyHold = $mk($a, 'LiteTest TH T8 filter hold legacy', $aid, [$aid], ['status' => 'on_hold']);
    $chosenHold = $mk($a, 'LiteTest TH T8 filter hold chosen', $aid, [$aid], ['status' => 'on_hold', 'status_id' => $onHold]);
    $plain = $mk($a, 'LiteTest TH T8 filter plain', $aid, [$aid], ['status' => 'in_progress']);
    $ids = fn (array $q): array => (function (array $x) { sort($x); return $x; })($listedIds($a->request('GET', '/tasks', $q + ['q' => 'LiteTest TH T8 filter'])['body']));
    LiteAssert::same([$w], $ids(['status_id' => (string) $waiting]), 'custom status');
    $both = [$legacyHold, $chosenHold];
    sort($both);
    LiteAssert::same($both, $ids(['status_id' => (string) $onHold]), 'an original: chosen + untouched legacy');
    $all = [$w, $legacyHold, $chosenHold, $plain];
    sort($all);
    LiteAssert::same($all, $ids([]), 'no filter');
    $legacyIn = [$w, $plain];
    sort($legacyIn);
    LiteAssert::same($legacyIn, $ids(['status' => 'in_progress']), 'the older ?status= (legacy value) still works');
    for ($i = 0; $i < 27; $i++) {
        $mk($a, 'LiteTest TH T8 filter page ' . $i, $aid, [$aid], ['status' => 'in_progress', 'status_id' => $waiting]);
    }
    $page = $a->request('GET', '/tasks', ['q' => 'LiteTest TH T8 filter', 'status_id' => (string) $waiting])['body'];
    LiteAssert::contains('status_id=' . $waiting, $page, 'page links keep the status');
    LiteAssert::contains('<option value="' . $waiting . '" selected>LiteTest Waiting T8-10</option>', $page, 'filter select');
    LiteAssert::contains('href="/tasks/statuses"', $page, 'manage button for an organization admin');
    LiteAssert::contains('name="return_to" value="/tasks?q=LiteTest+TH+T8+filter&amp;status_id=' . $waiting, $page, 'actions come back to the filtered list');

    $deal = $mk($a, 'LiteTest TH T8 deal task', $aid, [$aid], ['status' => 'in_progress', 'status_id' => $waiting, 'related_type' => 'deal', 'related_id' => $h->f->deal]);
    LiteAssert::contains('LiteTest Waiting T8-10</span>', $a->request('GET', '/deals/' . $h->f->deal)['body'], 'deal page');
    $a->locale('fa');
    try {
        $page = $a->request('GET', '/tasks/statuses')['body'];
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.statuses'), $page);
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.status.in_progress'), $page, 'originals translated');
        LiteAssert::contains('<option value="' . $waiting . '">', $page, 'a replacement option for in-progress statuses');
    } finally {
        $a->locale('en');
    }
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    $t8 = array_values(array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'tasks.status_') || str_starts_with($k, 'tasks.statuses')
        || in_array($k, ['tasks.flash.status_saved', 'tasks.flash.status_deleted'], true) || (str_starts_with($k, 'tasks.error.status_'))));
    LiteAssert::true(count($t8) >= 32, (string) count($t8));
    foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        foreach ($t8 as $key) {
            LiteAssert::true(isset($strings[$key]) && trim($strings[$key]) !== '', "{$lang}: {$key}");
        }
    }
    $up = explode('public function down', file_get_contents(dirname(__DIR__, 2) . '/modules/Sales/Migrations/0013_create_task_statuses_table.php'))[0];
    LiteAssert::false((bool) preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', $up), '0013 up() rewrites nothing');
    LiteAssert::contains('DROP TABLE IF EXISTS task_statuses', file_get_contents(dirname(__DIR__, 2) . '/modules/Sales/Migrations/0013_create_task_statuses_table.php'), 'reversible');
};

// ---- T9: task calendar ---------------------------------------------------------------------------------
$cal = fn (LocalApp $a, array $query = []): array => $a->request('GET', '/tasks/calendar', $query);
/** The HTML of one date's cell / column on a calendar page. */
$calCell = function (string $page, string $date): string {
    $parts = preg_split('/(?=<(?:div|section|table)[^>]*data-date=")/', $page);
    foreach ($parts as $part) {
        if (str_contains(substr($part, 0, 300), 'data-date="' . $date . '"')) {
            return $part;
        }
    }

    return '';
};
/** Task ids linked from some HTML (calendar chips link to /tasks/{id}). */
$chipIds = function (string $html): array {
    preg_match_all('#<a class="task-chip[^"]*" href="/tasks/(\d+)"#', $html, $m);
    $ids = array_values(array_unique(array_map('intval', $m[1])));
    sort($ids);

    return $ids;
};
$placed = fn (array $tasks, string $from, string $to): array => \Modules\Sales\Services\TaskCalendar::place($tasks, $from, $to);
$slim = fn (array $cell): array => [
    'all_day' => array_map(fn ($i) => (int) $i['task']['id'], $cell['all_day']),
    'timed' => array_map(fn ($i) => [(int) $i['task']['id'], $i['time'], $i['end']], $cell['timed']),
];

$tests['T9-1 the calendar needs tasks.view and the Sales module; "all" needs organization scope; list ↔ calendar links'] = function () use ($boot, $member, $withScopes, $cal) {
    $a = $boot();
    $a->as($a->admin);
    $r = $cal($a);
    LiteAssert::same(200, $r['status']);
    LiteAssert::contains('href="/tasks"', $r['body'], 'link back to the list');
    LiteAssert::contains('href="/tasks/calendar"', $a->request('GET', '/tasks')['body'], 'link from the list');
    $withScopes($a, ['tasks.view' => null], function () use ($a, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('GET', '/tasks/calendar'), 'no tasks.view');
    });
    $withScopes($a, ['tasks.view' => 'own'], function () use ($a, $member) {
        $a->as($member($a));
        LiteAssert::same('forbidden', $a->outcome('GET', '/tasks/calendar', ['view' => 'all']), 'all needs organization scope');
        LiteAssert::same('ok', $a->outcome('GET', '/tasks/calendar'), 'own tasks fine');
    });
    $a->as($a->admin);
    $a->setOrgEntitled('sales', false);
    try {
        LiteAssert::same('notfound', $a->outcome('GET', '/tasks/calendar'), 'Sales off');
    } finally {
        $a->setOrgEntitled('sales', true);
    }
};

$tests['T9-2 only the visible period is loaded (spanning tasks included); other organizations\' tasks, statuses and labels never show or filter'] = function () use ($boot, $h, $mk, $cal, $calCell, $chipIds, $systemStatus, $labelSvc) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $in = $mk($a, 'LiteTest TH T9 in', $aid, [$aid], ['due_at' => '2031-05-14 00:00:00', 'due_all_day' => 1]);
    $out = $mk($a, 'LiteTest TH T9 out', $aid, [$aid], ['due_at' => '2031-07-14 00:00:00', 'due_all_day' => 1]);
    $span = $mk($a, 'LiteTest TH T9 span', $aid, [$aid], ['start_at' => '2031-04-28 00:00:00', 'start_all_day' => 1, 'due_at' => '2031-06-03 00:00:00', 'due_all_day' => 1]);
    $none = $mk($a, 'LiteTest TH T9 undated', $aid, [$aid]);
    $page = $cal($a, ['mode' => 'month', 'date' => '2031-05-10', 'q' => 'LiteTest TH T9'])['body'];
    LiteAssert::same([$in, $span], $chipIds($page), 'in range + spanning; not outside, not undated');
    LiteAssert::false(in_array($out, $chipIds($page), true) || in_array($none, $chipIds($page), true));
    LiteAssert::same([$in, $span], $chipIds($calCell($page, '2031-05-14')));

    $svc = $a->container()->make(\Modules\Sales\Services\TaskService::class);
    $criteria = $svc->listCriteria($a->admin, 'mine', null, 'LiteTest TH T9');
    $rows = \App\Infrastructure\Repositories\OrganizationContext::runAs($a->orgId, fn () => $svc->calendarTasks($a->admin, $criteria, '2031-05-14 00:00:00', '2031-05-15 00:00:00'));
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    sort($ids);
    LiteAssert::same([$in, $span], $ids, 'a one-day range');

    if ($a->otherOrgId !== null) {
        $fu = (int) $h->f->foreignUser;
        $foreign = $mk($a, 'LiteTest TH T9 foreign', $fu, [$fu], ['organization_id' => $a->otherOrgId, 'due_at' => '2031-05-14 00:00:00', 'due_all_day' => 1]);
        $xStatus = (int) $systemStatus($a, 'pending', $a->otherOrgId)['id'];
        $a->pdo->prepare("INSERT INTO task_labels (organization_id, name, color) VALUES (?, 'LiteTest T9 foreign label', 'red')")->execute([$a->otherOrgId]);
        $xLabel = (int) $a->pdo->lastInsertId();
        $a->pdo->prepare('INSERT INTO task_label_links (task_id, label_id, organization_id) VALUES (?, ?, ?)')->execute([$foreign, $xLabel, $a->otherOrgId]);
        foreach ([[], ['status_id' => (string) $xStatus], ['label_id' => (string) $xLabel], ['view' => 'all']] as $extra) {
            $page = $cal($a, ['mode' => 'month', 'date' => '2031-05-10', 'q' => 'LiteTest TH T9'] + $extra)['body'];
            LiteAssert::false(in_array($foreign, $chipIds($page), true), 'never another organization\'s task: ' . json_encode($extra));
            LiteAssert::notContains('LiteTest TH T9 foreign', $page);
        }
        $page = $cal($a, ['mode' => 'month', 'date' => '2031-05-10', 'q' => 'LiteTest TH T9', 'status_id' => (string) $xStatus, 'label_id' => (string) $xLabel])['body'];
        LiteAssert::same([$in, $span], $chipIds($page), 'foreign status / label ids are ignored, not applied');
        LiteAssert::notContains('LiteTest T9 foreign label', $page, 'foreign labels not offered');
    }
};

$tests['T9-3 placement: all-day, timed (start–end), start only, due only, date-only due, spans across dates (clipped to the period), legacy rows, undated skipped'] = function () use ($placed, $slim) {
    $t = fn (int $id, array $f): array => ['id' => $id, 'title' => "t{$id}", 'status' => 'pending'] + $f + ['start_at' => null, 'due_at' => null, 'start_all_day' => 1, 'due_all_day' => 1];
    $tasks = [
        $t(1, ['due_at' => '2031-05-14 00:00:00', 'due_all_day' => 1]),
        $t(2, ['start_at' => '2031-05-14 09:00:00', 'start_all_day' => 0, 'due_at' => '2031-05-14 11:30:00', 'due_all_day' => 0]),
        $t(3, ['start_at' => '2031-05-14 08:15:00', 'start_all_day' => 0]),
        $t(4, ['due_at' => '2031-05-14 17:00:00', 'due_all_day' => 0]),
        $t(5, ['start_at' => '2031-05-14 00:00:00', 'start_all_day' => 1, 'due_at' => '2031-05-14 16:00:00', 'due_all_day' => 0]),
        $t(6, ['start_at' => '2031-05-13 10:00:00', 'start_all_day' => 0, 'due_at' => '2031-05-15 17:00:00', 'due_all_day' => 0]),
        $t(7, ['start_at' => '2031-05-01 00:00:00', 'start_all_day' => 1, 'due_at' => '2031-06-30 00:00:00', 'due_all_day' => 1]),
        $t(8, ['due_at' => '2031-05-14 13:00:00', 'due_all_day' => null]),
        $t(9, ['due_at' => '2031-05-14 00:00:00', 'due_all_day' => null]),
        $t(10, ['due_at' => '2031-05-14 00:00:00', 'due_all_day' => 0]),
        $t(11, []),
    ];
    $p = $placed($tasks, '2031-05-13', '2031-05-16');
    LiteAssert::same(['2031-05-13', '2031-05-14', '2031-05-15'], array_keys($p), 'only the period\'s dates');
    LiteAssert::same(['all_day' => [6 => 7], 'timed' => [[6, '10:00', null]]], ['all_day' => array_combine([6], $slim($p['2031-05-13'])['all_day']), 'timed' => $slim($p['2031-05-13'])['timed']], 'span first day: from its start time; long span: all day');
    LiteAssert::same(
        ['all_day' => [1, 6, 7, 9], 'timed' => [[10, '00:00', null], [3, '08:15', null], [2, '09:00', '11:30'], [8, '13:00', null], [5, '16:00', null], [4, '17:00', null]]],
        $slim($p['2031-05-14']),
        'all-day / timed / start only / due only / date-only due / legacy / explicit midnight'
    );
    LiteAssert::same(['all_day' => [7], 'timed' => [[6, null, '17:00']]], $slim($p['2031-05-15']), 'span last day: until its due time');
    LiteAssert::true($p['2031-05-14']['all_day'][1]['continues'] && !$p['2031-05-14']['all_day'][0]['continues'], 'spans are marked');
    foreach ($p as $cell) {
        foreach (array_merge($cell['all_day'], $cell['timed']) as $item) {
            LiteAssert::true((int) $item['task']['id'] !== 11, 'undated never placed');
        }
    }
    LiteAssert::same([], $placed([$t(12, ['due_at' => '2031-05-20 00:00:00'])], '2031-05-13', '2031-05-16')['2031-05-14']['all_day'], 'outside the period: nowhere');
};

$tests['T9-4 C3 wall clock: a late-evening timed task stays on its own date and time (never shifted by any time zone); week starts / months follow the user\'s calendar (Jalali in Persian)'] = function () use ($boot, $mk, $cal, $calCell, $chipIds) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $late = $mk($a, 'LiteTest TH T9 late', $aid, [$aid], ['due_at' => '2031-03-20 23:30:00', 'due_all_day' => 0]);
    $early = $mk($a, 'LiteTest TH T9 early', $aid, [$aid], ['start_at' => '2031-03-21 00:15:00', 'start_all_day' => 0]);
    $before = date_default_timezone_get();
    foreach (['UTC', 'America/Los_Angeles', 'Pacific/Kiritimati'] as $zone) {
        date_default_timezone_set($zone);
        try {
            $day = $cal($a, ['mode' => 'day', 'date' => '2031-03-20', 'q' => 'LiteTest TH T9'])['body'];
            LiteAssert::same([$late], $chipIds($day), "{$zone}: the 20th has the late task only");
            LiteAssert::contains('<span class="ltr">23:30</span>', $day, "{$zone}: at 23:30 as stored");
            LiteAssert::same([$early], $chipIds($cal($a, ['mode' => 'day', 'date' => '2031-03-21', 'q' => 'LiteTest TH T9'])['body']), "{$zone}: the 21st has the early one");
        } finally {
            date_default_timezone_set($before);
        }
    }
    LiteAssert::same('2031-03-20 23:30:00', $a->pdo->query("SELECT due_at FROM tasks WHERE id = {$late}")->fetchColumn(), 'stored value untouched');

    $C = \Modules\Sales\Services\TaskCalendar::class;
    LiteAssert::same(['2031-05-12', '2031-05-10'], [$C::weekStart('2031-05-14', 'en'), $C::weekStart('2031-05-14', 'fa')], 'Monday / Saturday');
    LiteAssert::same(['2031-02-01', '2031-02-28'], $C::monthBounds('2031-02-14', 'en'), 'Gregorian month');
    [$first, $last] = $C::monthBounds('2031-05-14', 'fa');
    $j = fn (string $d): array => \App\Infrastructure\Localization\LocaleFormatter::gregorianToJalali((int) substr($d, 0, 4), (int) substr($d, 5, 2), (int) substr($d, 8, 2));
    LiteAssert::same([1, $j('2031-05-14')[1]], [$j($first)[2], $j($last)[1]], 'Jalali month: starts on day 1');
    LiteAssert::true($j(date('Y-m-d', strtotime($last . ' +1 day')))[2] === 1 && $j(date('Y-m-d', strtotime($first . ' -1 day')))[1] !== $j($first)[1], 'and ends on its last day');
    $period = $C::period('month', '2031-05-14', 'fa');
    LiteAssert::same([$first, $last], [$period['month_from'], $period['month_to']]);
    LiteAssert::same(0, (strtotime($period['to']) - strtotime($period['from'])) / 86400 % 7, 'whole weeks');
    $a->locale('fa');
    try {
        $page = $cal($a, ['mode' => 'month', 'date' => '2031-05-14'])['body'];
        LiteAssert::contains((new \App\Infrastructure\Localization\LocaleFormatter('fa'))->date($first), $page, 'Jalali period title');
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.calendar.weekday_short.6'), $page);
    } finally {
        $a->locale('en');
    }
};

$tests['T9-5 a chip shows title, time, custom status (color) and labels, links to the task page; closed tasks muted; month / week / day and navigation render'] = function () use ($boot, $mk, $cal, $calCell, $chipIds, $newStatus, $labelSvc) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $waiting = $newStatus($a, 'LiteTest T9 Waiting', 'in_progress', 'orange');
    $t = $mk($a, 'LiteTest TH T9 chip <b>', $aid, [$aid], ['status' => 'in_progress', 'status_id' => $waiting, 'start_at' => '2031-10-15 09:00:00', 'start_all_day' => 0, 'due_at' => '2031-10-15 10:30:00', 'due_all_day' => 0]);
    $label = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest T9 label', 'color' => 'sky'])['id'];
    $a->pdo->prepare('INSERT INTO task_label_links (task_id, label_id, organization_id) VALUES (?, ?, ?)')->execute([$t, $label, $a->orgId]);
    $done = $mk($a, 'LiteTest TH T9 done', $aid, [$aid], ['status' => 'completed', 'due_at' => '2031-10-15 00:00:00', 'due_all_day' => 1]);
    foreach (['month', 'week', 'day'] as $mode) {
        $page = $cal($a, ['mode' => $mode, 'date' => '2031-10-15', 'q' => 'LiteTest TH T9'])['body'];
        $cell = $calCell($page, '2031-10-15');
        LiteAssert::contains('<a class="task-chip task-chip--warning" href="/tasks/' . $t . '">', $cell, "{$mode}: chip with the status color, linking to the task page");
        LiteAssert::contains('<span class="ltr">09:00–10:30</span>', $cell, "{$mode}: time range");
        LiteAssert::contains('LiteTest TH T9 chip &lt;b&gt;', $cell, "{$mode}: title, escaped");
        LiteAssert::contains('<span class="badge task-status badge--warning">LiteTest T9 Waiting</span>', $cell, "{$mode}: status");
        LiteAssert::contains('<span class="task-label task-label--sky">LiteTest T9 label</span>', $cell, "{$mode}: label");
        LiteAssert::true((bool) preg_match('#<a class="task-chip[^"]*task-chip--closed" href="/tasks/' . $done . '">#', $cell), "{$mode}: closed task muted");
        LiteAssert::contains(\App\Infrastructure\Theme\t('tasks.calendar.all_day'), $cell, "{$mode}: all-day item");
    }
    $page = $cal($a, ['mode' => 'week', 'date' => '2031-10-15', 'q' => 'LiteTest TH T9'])['body'];
    LiteAssert::contains('date=2031-10-06', $page, 'previous week');
    LiteAssert::contains('date=2031-10-20', $page, 'next week');
    LiteAssert::contains('q=LiteTest+TH+T9', $page, 'navigation keeps the filters');
    LiteAssert::contains('date=' . \App\Infrastructure\Time\OrganizationTimezone::shared()->today(), $page, 'today = the organization\'s today');
    LiteAssert::same(200, $cal($a, ['mode' => 'nonsense', 'date' => 'yesterday; DROP'])['status'], 'garbage parameters fall back (month, today)');
};

$tests['T9-6 filters: search, custom status (incl. untouched legacy tasks), label, created-by scope; hidden related records and other people\'s tasks stay hidden'] = function () use ($boot, $mk, $t5, $member, $withScopes, $cal, $chipIds, $systemStatus, $labelSvc) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $mid = (int) $a->member['id'];
    $day = ['due_at' => '2031-08-14 00:00:00', 'due_all_day' => 1];
    $hold = (int) $systemStatus($a, 'in_progress')['id'];
    $legacy = $mk($a, 'LiteTest TH T9F legacy', $aid, [$aid], ['status' => 'in_progress'] + $day);
    $chosen = $mk($a, 'LiteTest TH T9F chosen', $aid, [$aid], ['status' => 'in_progress', 'status_id' => $hold] + $day);
    $other = $mk($a, 'LiteTest TH T9F other', $aid, [$aid], $day);
    $label = (int) $labelSvc($a)->create($a->admin, ['name' => 'LiteTest T9F label', 'color' => 'green'])['id'];
    $a->pdo->prepare('INSERT INTO task_label_links (task_id, label_id, organization_id) VALUES (?, ?, ?)')->execute([$other, $label, $a->orgId]);
    $byMember = $mk($a, 'LiteTest TH T9F by member', $mid, [$aid], $day);
    $q = ['mode' => 'week', 'date' => '2031-08-14', 'q' => 'LiteTest TH T9F'];
    $all = [$legacy, $chosen, $other, $byMember];
    sort($all);
    LiteAssert::same($all, $chipIds($cal($a, $q)['body']), 'search');
    $both = [$legacy, $chosen];
    sort($both);
    LiteAssert::same($both, $chipIds($cal($a, $q + ['status_id' => (string) $hold])['body']), 'status (with legacy tasks of that status)');
    LiteAssert::same([$other], $chipIds($cal($a, $q + ['label_id' => (string) $label])['body']), 'label');
    $mine = [$legacy, $chosen, $other];
    sort($mine);
    LiteAssert::same($mine, $chipIds($cal($a, $q + ['view' => 'created'])['body']), 'created by me');

    $x = $t5($a);
    $secret = $mk($a, 'LiteTest TH T9F secret project', $aid, [$mid], ['related_type' => 'project', 'related_id' => $x->project] + $day);
    $adminOnly = $mk($a, 'LiteTest TH T9F admin only', $aid, [$aid], $day);
    $withScopes($a, ['projects.view' => null, 'tasks.view' => 'own'], function () use ($a, $member, $cal, $chipIds, $q, $secret, $adminOnly, $byMember) {
        $a->as($member($a));
        $ids = array_merge($chipIds($cal($a, $q)['body']), $chipIds($cal($a, $q + ['view' => 'created'])['body']));
        LiteAssert::false(in_array($secret, $ids, true), 'hidden project: not on the calendar');
        LiteAssert::false(in_array($adminOnly, $ids, true), 'not another user\'s task (own scope)');
        LiteAssert::true(in_array($byMember, $ids, true), 'their own');
        LiteAssert::true(in_array($a->outcome('GET', '/tasks/' . $adminOnly), ['forbidden', 'notfound'], true), 'its task page stays closed too');
    });
};

$tests['T9-7 "+" opens the existing New task dialog with the date (and hour) filled in; invalid values are ignored; creating still validates as before'] = function () use ($boot, $cal, $dialogHtml, $withScopes, $member) {
    $a = $boot();
    $a->as($a->admin);
    $page = $cal($a, ['mode' => 'day', 'date' => '2031-05-14'])['body'];
    LiteAssert::contains('href="/tasks?new=1&amp;due_at=2031-05-14&amp;due_time=09%3A00"', $page, 'hour slot link');
    LiteAssert::contains('href="/tasks?new=1&amp;due_at=2031-05-14"', $page, 'all-day link');
    $dialog = $dialogHtml($a->request('GET', '/tasks', ['new' => '1', 'due_at' => '2031-05-14', 'due_time' => '09:00'])['body']);
    LiteAssert::contains('name="due_at" value="2031-05-14"', $dialog);
    LiteAssert::contains('name="due_time" value="09:00"', $dialog);
    $r = $a->request('GET', '/tasks', ['new' => '1', 'due_at' => '2031-02-30', 'due_time' => '09:00']);
    LiteAssert::contains('name="due_at" value=""', $dialogHtml($r['body']), 'invalid date ignored');
    LiteAssert::notContains('field-error', $dialogHtml($r['body']), 'no error shown for a prefill');
    LiteAssert::contains('name="due_time" value=""', $dialogHtml($a->request('GET', '/tasks', ['new' => '1', 'due_at' => '2031-05-14', 'due_time' => '9am'])['body']), 'invalid time ignored');
    $withScopes($a, ['tasks.create' => null], function () use ($a, $member) {
        $a->as($member($a));
        LiteAssert::notContains('href="/tasks?new=1', $a->request('GET', '/tasks/calendar', ['mode' => 'day', 'date' => '2031-05-14'])['body'], 'no add links without tasks.create');
    });
};

$tests['T9-8 loading the calendar changes nothing: reminder, email, recurrence and status fields as before; recurring occurrences and legacy tasks show; only GET exists'] = function () use ($boot, $mk, $cal, $calCell, $chipIds, $runRecurrence, $recurCols, $remindCols, $childOf) {
    $a = $boot();
    $a->as($a->admin);
    $aid = (int) $a->admin['id'];
    $head = $mk($a, 'LiteTest TH T9R series', $aid, [$aid], ['due_at' => '2031-09-10 00:00:00', 'due_all_day' => 1, 'remind_email' => 1] + $remindCols('2031-09-09 08:00:00') + $recurCols('weekly', 1, '2031-09-10 00:00:00'));
    $runRecurrence($a, '2031-09-09 21:00:00'); // 00:30 on the 10th, Tehran
    $next = (int) $childOf($a, $head)['id'];
    $legacy = $mk($a, 'LiteTest TH T9R legacy', $aid, [$aid], ['status' => 'on_hold', 'due_at' => '2031-09-10 00:00:00']);
    $snapshot = fn (): array => $a->pdo->query("SELECT * FROM tasks WHERE id IN ({$head}, {$next}, {$legacy}) ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
    $before = $snapshot();
    $notes = (int) $a->pdo->query('SELECT COUNT(*) FROM task_notifications')->fetchColumn();
    foreach (['month', 'week', 'day'] as $mode) {
        $cal($a, ['mode' => $mode, 'date' => '2031-09-10']);
    }
    $page = $cal($a, ['mode' => 'month', 'date' => '2031-09-10', 'q' => 'LiteTest TH T9R'])['body'];
    LiteAssert::same($before, $snapshot(), 'no task field changed (reminder, email, recurrence, status)');
    LiteAssert::same($notes, (int) $a->pdo->query('SELECT COUNT(*) FROM task_notifications')->fetchColumn(), 'no notification created');
    LiteAssert::same([$head, $legacy], $chipIds($calCell($page, '2031-09-10')), 'the head and a legacy task on the 10th');
    LiteAssert::same([$next], $chipIds($calCell($page, '2031-09-17')), 'the generated occurrence on its own date');
    $shown = \Modules\Sales\Services\TaskStatusService::effective(['status' => 'on_hold', 'status_id' => null], $a->container()->make(\Modules\Sales\Services\TaskService::class)->statusMap());
    LiteAssert::contains('>' . ($shown['label'] ?? \App\Infrastructure\Theme\t('tasks.status.on_hold')) . '</span>', $calCell($page, '2031-09-10'), 'legacy status shown through the organization\'s status');
    LiteAssert::true(in_array($a->outcome('POST', '/tasks/calendar', [], ['date' => '2031-09-10']), ['notfound', 'status:405'], true), 'no write endpoint');
    LiteAssert::notContains('method="post" action="/tasks/calendar', $page, 'the page posts nothing to the calendar');
};

$tests['T9-9 regression + languages: list, search, pagination, dialog unchanged; every calendar key in five languages; no migration for T9'] = function () use ($boot, $dialogHtml) {
    $a = $boot();
    $a->as($a->admin);
    $list = $a->request('GET', '/tasks', ['q' => 'LiteTest'])['body'];
    LiteAssert::contains('name="q" value="LiteTest"', $list);
    LiteAssert::contains('<select id="task-new-status" name="status_id">', $dialogHtml($list));
    $en = require dirname(__DIR__, 2) . '/lang/en.php';
    $keys = array_values(array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'tasks.calendar.')));
    LiteAssert::same(37, count($keys));
    foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require dirname(__DIR__, 2) . "/lang/{$lang}.php";
        foreach ($keys as $key) {
            LiteAssert::true(isset($strings[$key]) && trim($strings[$key]) !== '', "{$lang}: {$key}");
        }
    }
    $migrations = array_map('basename', glob(dirname(__DIR__, 2) . '/modules/Sales/Migrations/*.php'));
    LiteAssert::same('0013_create_task_statuses_table.php', end($migrations), 'T9 needs no migration');
    $template = file_get_contents(dirname(__DIR__, 2) . '/themes/default/templates/sales/task-calendar.php');
    LiteAssert::notContains('<script', $template, 'no script, no external library');
    LiteAssert::notContains('style="', $template, 'no inline styles');
};

// ---- P0-8 atomic create (runs after the suite's rollback, see file header) ----------
$tests['P0-8.1 create is atomic: an assignee-row failure rolls the task back; suite rollback leaves nothing behind'] = function () use ($boot, $h, $svc, $count) {
    $a = $boot();
    $a->rollBack();
    foreach ($h->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    LiteAssert::same(0, $count($a, "SELECT COUNT(*) FROM tasks WHERE title LIKE 'LiteTest TH%'"), 'suite data rolled back');
    LiteAssert::same([], array_values(array_filter($h->files, 'is_file')), 'suite files removed');

    // No outer transaction now: create() must open its own. A session-only
    // TEMPORARY table shadows task_assignees (no implicit commit) so the
    // assignee write fails AFTER the task row was inserted.
    $title = 'LiteTest TH atomic ' . bin2hex(random_bytes(3));
    $a->pdo->exec('CREATE TEMPORARY TABLE task_assignees (id INT)');
    try {
        $thrown = false;
        try {
            $svc($a)->create($a->admin, ['title' => $title]);
        } catch (\PDOException) {
            $thrown = true;
        }
        LiteAssert::true($thrown, 'the assignee write failed');
        LiteAssert::false($a->pdo->inTransaction(), 'transaction closed');
        LiteAssert::same(0, $count($a, 'SELECT COUNT(*) FROM tasks WHERE title = ' . $a->pdo->quote($title)), 'no orphan task row');
    } finally {
        $a->pdo->exec('DROP TEMPORARY TABLE IF EXISTS task_assignees');
        // Safety net for a broken implementation: never leave a row in crm_local.
        $a->pdo->exec('DELETE FROM tasks WHERE title = ' . $a->pdo->quote($title));
    }
};

return $tests;
