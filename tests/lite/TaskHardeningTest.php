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
        LiteAssert::same([302, '/tasks'], [$r['status'], $r['location']], "{$label}: route redirects, no 500");
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
    $created = $a->request('GET', '/tasks', ['view' => 'created']);
    LiteAssert::same(200, $created['status']);
    LiteAssert::contains('LiteTest TH IR delegated<', $created['body'], 'delegated task listed');
    LiteAssert::contains('LiteTest TH IR delegated done', $created['body']);
    LiteAssert::notContains('LiteTest TH IR delegated archived', $created['body'], 'archived excluded');
    LiteAssert::notContains('LiteTest TH IR delegated deleted', $created['body'], 'soft-deleted excluded');
    LiteAssert::notContains('LiteTest TH IR members own', $created['body'], 'only tasks I created');
    LiteAssert::contains('value="/tasks?view=created"', $created['body'], 'actions return to the same view');
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
