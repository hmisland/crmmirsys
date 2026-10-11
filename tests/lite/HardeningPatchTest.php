<?php

declare(strict_types=1);

/**
 * Hardening patch (docs/hardening-patch-design.md): Follow-ups,
 * Sales task / Support ticket attachments.
 *
 * H2  follow-up type/priority: safe defaults on create, kept on update
 * H1  follow-up assignee / team / department of the user's organization (+ D1 rule engine)
 * H3  task + ticket attachment download: parent find() before serving
 * H4  ticket attachment upload: ticket find() before storing
 * H5  follow-up flash + validation messages translated (+ D3)
 *
 * crm_local only, one transaction, rolled back; files written by the suite
 * are removed at the end.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Core\Exceptions\ValidationException;
use Modules\Followups\Services\FollowUpService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';
$h->files = [];
register_shutdown_function(function () use ($h) {
    foreach ($h->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

$boot = function () use ($h): LocalApp {
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
    // Follow-ups, Sales and Support active + entitled for the org (inside the transaction).
    foreach (['followups', 'sales', 'support'] as $key) {
        $id = $a->pdo->query('SELECT id FROM modules WHERE `key` = ' . $a->pdo->quote($key))->fetchColumn();
        if ($id === false) {
            $a->rollBack();
            $h->reason = "module {$key} not installed in crm_local";
            throw new LiteSkip($h->reason);
        }
        $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $id]);
        $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $id]);
        $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $id]);
    }
    $a->as($a->admin);
    $h->app = $a;

    return $a;
};
$svc = fn (LocalApp $a): FollowUpService => $a->container()->make(FollowUpService::class);
$row = fn (LocalApp $a, int $id): array => $a->pdo->query("SELECT * FROM follow_ups WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);

$tests = [];

// ---- Stage 1: H2 type / priority ---------------------------------------------
$tests['H2.1 create with type/priority MISSING → task / normal (no error)'] = function () use ($boot, $svc) {
    $a = $boot();
    $f = $svc($a)->create($a->admin, ['title' => 'LiteTest H2 missing']);
    LiteAssert::same('task', $f['type']);
    LiteAssert::same('normal', $f['priority']);
};

$tests['H2.2 create with empty or unknown type/priority → task / normal; valid values kept'] = function () use ($boot, $svc) {
    $a = $boot();
    foreach ([['', ''], ['bogus', 'extreme'], [['x'], ['y']]] as [$type, $priority]) {
        $f = $svc($a)->create($a->admin, ['title' => 'LiteTest H2 bad', 'type' => $type, 'priority' => $priority]);
        LiteAssert::same(['task', 'normal'], [$f['type'], $f['priority']], json_encode([$type, $priority]));
    }
    $ok = $svc($a)->create($a->admin, ['title' => 'LiteTest H2 ok', 'type' => 'call', 'priority' => 'urgent']);
    LiteAssert::same(['call', 'urgent'], [$ok['type'], $ok['priority']]);
};

$tests['H2.3 update with type/priority missing or invalid keeps the stored values; valid values change them'] = function () use ($boot, $svc, $row) {
    $a = $boot();
    $f = $svc($a)->create($a->admin, ['title' => 'LiteTest H2 upd', 'type' => 'meeting', 'priority' => 'high']);
    $svc($a)->update($a->admin, (int) $f['id'], ['title' => 'LiteTest H2 upd 2']);
    LiteAssert::same(['meeting', 'high'], [$row($a, (int) $f['id'])['type'], $row($a, (int) $f['id'])['priority']], 'missing → kept');
    $svc($a)->update($a->admin, (int) $f['id'], ['title' => 'LiteTest H2 upd 3', 'type' => 'nope', 'priority' => '']);
    LiteAssert::same(['meeting', 'high'], [$row($a, (int) $f['id'])['type'], $row($a, (int) $f['id'])['priority']], 'invalid → kept');
    $svc($a)->update($a->admin, (int) $f['id'], ['title' => 'LiteTest H2 upd 4', 'type' => 'email', 'priority' => 'low']);
    LiteAssert::same(['email', 'low'], [$row($a, (int) $f['id'])['type'], $row($a, (int) $f['id'])['priority']], 'valid → changed');
};

$tests['H2.4 through the route: POST without type/priority creates (no 500)'] = function () use ($boot) {
    $a = $boot();
    $r = $a->request('POST', '/followups', [], ['title' => 'LiteTest H2 route']);
    LiteAssert::same(302, $r['status']);
    LiteAssert::same(['task', 'normal'], array_values($a->pdo->query("SELECT type, priority FROM follow_ups WHERE title = 'LiteTest H2 route'")->fetch(\PDO::FETCH_ASSOC)));
};

// ---- Stage 2: H1 assignee / team / department (+ D1 rule engine) --------------

/** Another organization's user / team / department (created inside the transaction when missing). */
$foreign = function (LocalApp $a): ?array {
    if ($a->otherOrgId === null) {
        return null;
    }
    $user = $a->pdo->query("SELECT id, first_name, last_name FROM users WHERE organization_id = {$a->otherOrgId} ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($user === false) {
        $a->pdo->prepare("INSERT INTO users (organization_id, first_name, last_name, email, password_hash, status) VALUES (?, 'LiteTestXorg', 'Person', ?, 'x', 'active')")->execute([$a->otherOrgId, 'litetest-xorg-' . bin2hex(random_bytes(3)) . '@example.test']);
        $user = ['id' => (int) $a->pdo->lastInsertId(), 'first_name' => 'LiteTestXorg', 'last_name' => 'Person'];
    }
    $a->pdo->prepare('INSERT INTO teams (organization_id, name) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG team']);
    $team = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare('INSERT INTO departments (organization_id, name) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG dept']);
    $dept = (int) $a->pdo->lastInsertId();

    return ['user' => (int) $user['id'], 'name' => trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')), 'team' => $team, 'department' => $dept];
};
$expectField = function (callable $fn, string $field): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::true(isset($e->errors[$field]), "expected {$field}, got " . json_encode($e->errors));

        return;
    }
    throw new LiteAssertionFailed("expected a ValidationException on {$field}");
};

$tests['H1.1 positive: active org user as assignee, own team + department, and empty values are accepted'] = function () use ($boot, $svc) {
    $a = $boot();
    $a->pdo->prepare('INSERT INTO teams (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteTest own team']);
    $team = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare('INSERT INTO departments (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteTest own dept']);
    $dept = (int) $a->pdo->lastInsertId();
    $f = $svc($a)->create($a->admin, ['title' => 'LiteTest H1 ok', 'assignee_user_id' => (string) $a->member['id'], 'team_id' => (string) $team, 'department_id' => (string) $dept]);
    LiteAssert::same([(int) $a->member['id'], $team, $dept], [(int) $f['assignee_user_id'], (int) $f['team_id'], (int) $f['department_id']]);
    $none = $svc($a)->create($a->admin, ['title' => 'LiteTest H1 none', 'assignee_user_id' => '', 'team_id' => '', 'department_id' => '']);
    LiteAssert::same([null, null, null], [$none['assignee_user_id'], $none['team_id'], $none['department_id']]);
};

$tests['H1.2 another organization\'s user / team / department, unknown ids → 422, nothing stored (service + route)'] = function () use ($boot, $svc, $foreign, $expectField) {
    $a = $boot();
    $x = $foreign($a);
    $before = (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE organization_id = {$a->orgId}")->fetchColumn();
    $cases = [['assignee_user_id', '999999999'], ['team_id', '999999999'], ['department_id', '999999999']];
    if ($x !== null) {
        array_push($cases, ['assignee_user_id', (string) $x['user']], ['team_id', (string) $x['team']], ['department_id', (string) $x['department']]);
    }
    foreach ($cases as [$field, $value]) {
        $expectField(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest H1 bad', $field => $value]), $field);
        LiteAssert::same(422, $a->request('POST', '/followups', [], ['title' => 'LiteTest H1 bad route', 'type' => 'task', 'priority' => 'normal', $field => $value])['status'], "route {$field}={$value}");
    }
    LiteAssert::same($before, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE organization_id = {$a->orgId}")->fetchColumn(), 'nothing stored');
};

$tests['H1.3 inactive user: refused as a NEW assignee, but an already-saved one can be kept on edit; switching to a foreign one is refused'] = function () use ($boot, $svc, $foreign, $expectField, $row) {
    $a = $boot();
    $f = $svc($a)->create($a->admin, ['title' => 'LiteTest H1 keep', 'assignee_user_id' => (string) $a->member['id']]);
    $a->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([(int) $a->member['id']]);
    try {
        $expectField(fn () => $svc($a)->create($a->admin, ['title' => 'LiteTest H1 new inactive', 'assignee_user_id' => (string) $a->member['id']]), 'assignee_user_id');
        $svc($a)->update($a->admin, (int) $f['id'], ['title' => 'LiteTest H1 keep 2', 'assignee_user_id' => (string) $a->member['id']]);
        LiteAssert::same('LiteTest H1 keep 2', $row($a, (int) $f['id'])['title'], 'edit with the saved (now inactive) assignee works');
        // The form offers that saved assignee, but not other inactive users.
        $assigneeSelect = function (string $html): string {
            $start = strpos($html, 'name="assignee_user_id"');

            return $start === false ? '' : substr($html, $start, strpos($html, '</select>', $start) - $start);
        };
        $form = $assigneeSelect($a->request('GET', "/followups/{$f['id']}/edit")['body']);
        LiteAssert::contains('value="' . $a->member['id'] . '"', $form, 'saved assignee offered');
        $other = $svc($a)->create($a->admin, ['title' => 'LiteTest H1 other']);
        LiteAssert::notContains('value="' . $a->member['id'] . '"', $assigneeSelect($a->request('GET', "/followups/{$other['id']}/edit")['body']), 'inactive user not offered elsewhere');
    } finally {
        $a->pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int) $a->member['id']]);
    }
    $x = $foreign($a);
    if ($x !== null) {
        $expectField(fn () => $svc($a)->update($a->admin, (int) $f['id'], ['title' => 'x', 'assignee_user_id' => (string) $x['user']]), 'assignee_user_id');
        LiteAssert::same((int) $a->member['id'], (int) $row($a, (int) $f['id'])['assignee_user_id'], 'unchanged');
    }
};

$tests['H1.4 cross-tenant: a foreign assignee name never appears in the follow-up list after an attempted POST'] = function () use ($boot, $foreign) {
    $a = $boot();
    $x = $foreign($a);
    if ($x === null) {
        throw new LiteSkip('only one organization');
    }
    $a->pdo->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?')->execute(['LiteTestLeak', 'Victim', $x['user']]);
    $a->request('POST', '/followups', [], ['title' => 'LiteTest H1 leak', 'type' => 'task', 'priority' => 'normal', 'assignee_user_id' => (string) $x['user']]);
    LiteAssert::notContains('LiteTestLeak', $a->request('GET', '/followups')['body']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE organization_id = {$a->orgId} AND assignee_user_id = {$x['user']}")->fetchColumn());
};

$tests['H1.5 D1: a rule whose fixed assignee is another organization\'s (or unknown / inactive) user assigns the follow-up to the actor'] = function () use ($boot, $foreign) {
    $a = $boot();
    $x = $foreign($a);
    $targets = ['unknown' => 999999999];
    if ($x !== null) {
        $targets['foreign'] = $x['user'];
    }
    $a->pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([(int) $a->member['id']]);
    $targets['inactive'] = (int) $a->member['id'];
    try {
        $stage = 900000;
        foreach ($targets as $label => $userId) {
            $a->pdo->prepare("INSERT INTO follow_up_rules (organization_id, name, event_type, action_configuration_json, enabled, created_by_user_id) VALUES (?, ?, 'DealStageChanged', ?, 1, ?)")
                ->execute([$a->orgId, "LiteTest D1 {$label}", json_encode(['title' => "LiteTest D1 {$label}", 'assign_to' => 'specific_user', 'assign_to_user_id' => $userId]), (int) $a->admin['id']]);
            $ruleId = (int) $a->pdo->lastInsertId();
            $a->container()->make(\App\Core\Events\EventBus::class)->dispatch(new \Modules\Sales\Events\DealStageChanged($a->orgId, 999000 + $stage, ++$stage, 'LiteTest stage', false, false, (int) $a->admin['id']));
            $created = $a->pdo->query("SELECT owner_id, assignee_user_id FROM follow_ups WHERE source_type = 'rule' AND source_id = {$ruleId}")->fetch(\PDO::FETCH_ASSOC);
            LiteAssert::true($created !== false, "{$label}: follow-up created");
            LiteAssert::same([(int) $a->admin['id'], (int) $a->admin['id']], [(int) $created['owner_id'], (int) $created['assignee_user_id']], "{$label}: assigned to the actor");
            $a->pdo->prepare('UPDATE follow_up_rules SET enabled = 0 WHERE id = ?')->execute([$ruleId]);
        }
    } finally {
        $a->pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int) $a->member['id']]);
    }
    // …while a valid active user of the organization is still honoured.
    $a->pdo->prepare("INSERT INTO follow_up_rules (organization_id, name, event_type, action_configuration_json, enabled, created_by_user_id) VALUES (?, 'LiteTest D1 valid', 'DealStageChanged', ?, 1, ?)")
        ->execute([$a->orgId, json_encode(['title' => 'LiteTest D1 valid', 'assign_to' => 'specific_user', 'assign_to_user_id' => (int) $a->member['id']]), (int) $a->admin['id']]);
    $ruleId = (int) $a->pdo->lastInsertId();
    $a->container()->make(\App\Core\Events\EventBus::class)->dispatch(new \Modules\Sales\Events\DealStageChanged($a->orgId, 998999, 998999, 'LiteTest stage', false, false, (int) $a->admin['id']));
    LiteAssert::same((int) $a->member['id'], (int) $a->pdo->query("SELECT owner_id FROM follow_ups WHERE source_type = 'rule' AND source_id = {$ruleId}")->fetchColumn());
    $a->pdo->prepare('UPDATE follow_up_rules SET enabled = 0 WHERE id = ?')->execute([$ruleId]);
};

// ---- Stage 3: H3 task + ticket attachment download -----------------------------

$insert = function (LocalApp $a, string $sql, array $params): int {
    $a->pdo->prepare($sql)->execute($params);

    return (int) $a->pdo->lastInsertId();
};
/** An attachment row + a real stored file for $type #$id; returns [id, absolute path]. */
$attachment = function (LocalApp $a, string $type, int $id, int $orgId = 0) use ($h): array {
    $orgId = $orgId > 0 ? $orgId : $a->orgId;
    $stored = $orgId . '/litetest-' . bin2hex(random_bytes(8)) . '.txt';
    $a->pdo->prepare('INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orgId, $type, $id, (int) $a->admin['id'], 'litetest.txt', $stored, 'text/plain', 6]);
    $attId = (int) $a->pdo->lastInsertId();
    $path = $a->container()->make(\App\Application\Services\AttachmentService::class)->absolutePath(['stored_path' => $stored]);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'secret');
    $h->files[] = $path;

    return [$attId, $path];
};
/** Temporarily sets a member-role grant (null = remove); always restored. */
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

$tests['H3.1 task download: allowed for admin, the creator and the assignee (OWN); body is the file'] = function () use ($boot, $insert, $attachment, $withMemberScope) {
    $a = $boot();
    $mine = $insert($a, 'INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest H3 member-created', (int) $a->member['id']]);
    $assigned = $insert($a, 'INSERT INTO tasks (organization_id, title, created_by_user_id, assigned_to_user_id) VALUES (?, ?, ?, ?)', [$a->orgId, 'LiteTest H3 assigned', (int) $a->admin['id'], (int) $a->member['id']]);
    [$a1] = $attachment($a, 'task', $mine);
    [$a2] = $attachment($a, 'task', $assigned);
    $r = $a->request('GET', "/tasks/{$mine}/attachments/{$a1}/download");
    LiteAssert::same([200, 'secret'], [$r['status'], $r['body']], 'admin');
    $withMemberScope($a, 'tasks.view', 'own', function () use ($a, $mine, $assigned, $a1, $a2) {
        $a->as($a->member);
        LiteAssert::same('secret', $a->request('GET', "/tasks/{$mine}/attachments/{$a1}/download")['body'], 'creator');
        LiteAssert::same('secret', $a->request('GET', "/tasks/{$assigned}/attachments/{$a2}/download")['body'], 'assignee');
    });
};

$tests['H3.2 task download refused: OWN user not creator/assignee → 403; no tasks.view → 403; other task\'s file / missing task → 404'] = function () use ($boot, $insert, $attachment, $withMemberScope) {
    $a = $boot();
    $t = $insert($a, 'INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest H3 admin task', (int) $a->admin['id']]);
    $t2 = $insert($a, 'INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest H3 other task', (int) $a->admin['id']]);
    [$att] = $attachment($a, 'task', $t);
    [$contactAtt] = $attachment($a, 'contact', (int) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn());
    $withMemberScope($a, 'tasks.view', 'own', function () use ($a, $t, $att) {
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('GET', "/tasks/{$t}/attachments/{$att}/download"), 'OWN, not creator/assignee');
    });
    $withMemberScope($a, 'tasks.view', null, function () use ($a, $t, $att) {
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('GET', "/tasks/{$t}/attachments/{$att}/download"), 'no tasks.view');
    });
    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$t2}/attachments/{$att}/download"), 'task A file via task B');
    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/{$t}/attachments/{$contactAtt}/download"), 'a contact file via a task');
    LiteAssert::same('notfound', $a->outcome('GET', "/tasks/999999999/attachments/{$att}/download"), 'missing task');
};

$tests['H3.3 ticket download: allowed with tickets.view; without it → 403; other ticket\'s / contact\'s file → 404; missing ticket → 404'] = function () use ($boot, $insert, $attachment, $withMemberScope) {
    $a = $boot();
    $t = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest H3 ticket']);
    $t2 = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest H3 ticket 2']);
    [$att] = $attachment($a, 'ticket', $t);
    [$contactAtt] = $attachment($a, 'contact', (int) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn());
    $a->as($a->member);
    LiteAssert::same('secret', $a->request('GET', "/support/tickets/{$t}/attachments/{$att}/download")['body'], 'member with tickets.view');
    $a->as($a->admin);
    $withMemberScope($a, 'tickets.view', null, function () use ($a, $t, $att) {
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('GET', "/support/tickets/{$t}/attachments/{$att}/download"), 'no tickets.view');
    });
    LiteAssert::same('notfound', $a->outcome('GET', "/support/tickets/{$t2}/attachments/{$att}/download"), 'ticket A file via ticket B');
    LiteAssert::same('notfound', $a->outcome('GET', "/support/tickets/{$t}/attachments/{$contactAtt}/download"), 'a contact file via a ticket');
    LiteAssert::same('notfound', $a->outcome('GET', "/support/tickets/999999999/attachments/{$att}/download"), 'missing ticket');
};

$tests['H3.4 cross-tenant: another organization\'s task / ticket / attachment ids → 404, no bytes served'] = function () use ($boot, $insert, $attachment) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $ft = $insert($a, 'INSERT INTO tasks (organization_id, title) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest XORG task']);
    $fk = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest XORG ticket']);
    [$fta] = $attachment($a, 'task', $ft, $a->otherOrgId);
    [$fka] = $attachment($a, 'ticket', $fk, $a->otherOrgId);
    $mine = $insert($a, 'INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest H3 mine', (int) $a->admin['id']]);
    $myTicket = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest H3 my ticket']);
    foreach (["/tasks/{$ft}/attachments/{$fta}/download", "/tasks/{$mine}/attachments/{$fta}/download", "/support/tickets/{$fk}/attachments/{$fka}/download", "/support/tickets/{$myTicket}/attachments/{$fka}/download"] as $path) {
        LiteAssert::same('notfound', $a->outcome('GET', $path), $path);
    }
};

// ---- Stage 4: H4 ticket attachment upload ------------------------------------

/**
 * POST a real multipart file through the real router. In CLI,
 * move_uploaded_file() can't succeed, so an ACCEPTED request ends in the
 * service's "Could not save" flash (a 302) — what matters here is whether
 * the request is refused BEFORE upload() is reached.
 * @return string "ok:<status>" | "forbidden" | "notfound" | "csrf"
 */
$upload = function (LocalApp $a, string $path) use ($h): string {
    $tmp = tempnam(sys_get_temp_dir(), 'lt');
    file_put_contents($tmp, 'upload-probe');
    $h->files[] = $tmp;
    $a->container()->make(\App\Infrastructure\Modules\ModuleGate::class)->reset();
    $_SERVER['REQUEST_URI'] = $path;
    $request = new \App\Core\Request('POST', $path, [], ['_csrf' => \App\Infrastructure\Security\Csrf::token()], [], [], [
        'file' => ['name' => 'probe.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 12],
    ]);
    try {
        $response = $a->app->router->dispatch($request);

        return 'ok:' . (new \ReflectionProperty(\App\Core\Response::class, 'status'))->getValue($response);
    } catch (\App\Core\Exceptions\AuthorizationException) {
        return 'forbidden';
    } catch (\App\Core\Exceptions\NotFoundException) {
        return 'notfound';
    }
};

$tests['H4.1 ticket upload refused BEFORE storing: missing ticket / other organization\'s ticket → 404; no tickets.view → 403; nothing written'] = function () use ($boot, $insert, $upload, $withMemberScope) {
    $a = $boot();
    $t = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest H4 ticket']);
    $counts = fn () => [
        (int) $a->pdo->query("SELECT COUNT(*) FROM attachments WHERE attachable_type = 'ticket'")->fetchColumn(),
        (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE type = 'attachment_added' AND subject_type = 'ticket'")->fetchColumn(),
    ];
    $before = $counts();
    LiteAssert::same('notfound', $upload($a, '/support/tickets/999999999/attachments'), 'missing ticket');
    if ($a->otherOrgId !== null) {
        $foreign = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest XORG H4']);
        LiteAssert::same('notfound', $upload($a, "/support/tickets/{$foreign}/attachments"), 'other organization\'s ticket');
    }
    $withMemberScope($a, 'tickets.view', null, function () use ($a, $t, $upload) {
        $a->as($a->member);
        LiteAssert::same('forbidden', $upload($a, "/support/tickets/{$t}/attachments"), 'no tickets.view');
    });
    LiteAssert::same($before, $counts(), 'no attachment row, no timeline entry');
};

$tests['H4.2 ticket upload allowed with tickets.view (D2: no edit permission needed) — the request reaches the upload step'] = function () use ($boot, $insert, $upload) {
    $a = $boot();
    $t = $insert($a, 'INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest H4 ok']);
    LiteAssert::same('ok:302', $upload($a, "/support/tickets/{$t}/attachments"), 'admin');
    $a->as($a->member); // member: tickets.view (organization), tickets.edit irrelevant
    LiteAssert::same('ok:302', $upload($a, "/support/tickets/{$t}/attachments"), 'member with tickets.view');
    $a->as($a->admin);
    // Without a file: same redirect as before the patch.
    LiteAssert::same(302, $a->request('POST', "/support/tickets/{$t}/attachments")['status']);
};

// ---- Stage 5: H5 + D3 translated messages ------------------------------------

$h5Keys = [
    'followups.flash.created', 'followups.flash.updated', 'followups.flash.deleted', 'followups.flash.completed',
    'followups.flash.cancelled', 'followups.flash.rescheduled', 'followups.flash.reschedule_failed',
    'followups.flash.rule_created', 'followups.flash.rule_create_failed', 'followups.flash.rule_updated', 'followups.flash.rule_deleted',
    'followups.error.title_required', 'followups.error.due_at_required', 'followups.error.rule_name_required',
    'followups.error.rule_event_required', 'followups.error.rule_action_required', 'followups.error.rule_action_json',
    'followups.error.rule_conditions_json', 'followups.error.invalid_assignee', 'followups.error.invalid_team', 'followups.error.invalid_department',
];

$tests['H5.1 every new message key exists (non-empty) in all five languages'] = function () use ($h5Keys) {
    $root = dirname(__DIR__, 2);
    $missing = [];
    foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
        $strings = require "{$root}/lang/{$lang}.php";
        foreach ($h5Keys as $key) {
            if (!isset($strings[$key]) || trim((string) $strings[$key]) === '') {
                $missing[] = "{$lang}:{$key}";
            }
        }
    }
    LiteAssert::same([], $missing, implode(', ', $missing));
};

$tests['H5.2 source scan: no English flash or validation literal left in the Follow-ups controllers / services'] = function () {
    $dir = dirname(__DIR__, 2) . '/modules/Followups';
    $leftovers = [];
    foreach (['Controllers/FollowUpController.php', 'Controllers/FollowUpRuleController.php', 'Services/FollowUpService.php', 'Services/FollowUpRuleService.php'] as $file) {
        $code = (string) file_get_contents("{$dir}/{$file}");
        // setFlash('…', 'Literal') or ?? 'Literal' fallbacks, and ValidationException([... => ['Literal']])
        if (preg_match_all("/setFlash\\([^,]+,\\s*'[A-Z][^']*'\\)|\\?\\?\\s*'[A-Z][^']*\\.'|=>\\s*\\['[A-Z][^']*'\\]/", $code, $m)) {
            foreach ($m[0] as $hit) {
                $leftovers[] = "{$file}: {$hit}";
            }
        }
    }
    LiteAssert::same([], $leftovers, implode(' | ', $leftovers));
};

$tests['H5.3 each follow-up action stores its flash in the user\'s language (fa, ar, tr, fr, en)'] = function () use ($boot, $svc) {
    $a = $boot();
    $root = dirname(__DIR__, 2);
    $problems = [];
    foreach (['fa', 'ar', 'tr', 'fr', 'en'] as $lang) {
        $strings = require "{$root}/lang/{$lang}.php";
        $a->locale($lang);
        $check = function (string $method, string $path, array $body, string $flashType, string $key) use ($a, $strings, $lang, &$problems): void {
            \App\Infrastructure\Security\Session::getFlash($flashType); // clear
            $a->request($method, $path, [], $body);
            $got = \App\Infrastructure\Security\Session::getFlash($flashType);
            if ($got !== $strings[$key]) {
                $problems[] = "{$lang} {$path}: expected [{$strings[$key]}], got [" . var_export($got, true) . ']';
            }
        };
        $check('POST', '/followups', ['title' => "LiteTest H5 {$lang}", 'type' => 'task', 'priority' => 'normal'], 'success', 'followups.flash.created');
        $id = (int) $a->pdo->query("SELECT id FROM follow_ups WHERE title = 'LiteTest H5 {$lang}' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $check('POST', "/followups/{$id}", ['title' => "LiteTest H5 {$lang} 2", 'type' => 'task', 'priority' => 'normal'], 'success', 'followups.flash.updated');
        $check('POST', "/followups/{$id}/reschedule", ['due_at' => ''], 'error', 'followups.error.due_at_required');
        $check('POST', "/followups/{$id}/reschedule", ['due_at' => '2030-01-01 10:00:00'], 'success', 'followups.flash.rescheduled');
        $check('POST', "/followups/{$id}/complete", [], 'success', 'followups.flash.completed');
        $id2 = (int) $svc($a)->create($a->admin, ['title' => "LiteTest H5 {$lang} c"])['id'];
        $check('POST', "/followups/{$id2}/cancel", [], 'success', 'followups.flash.cancelled');
        $check('POST', "/followups/{$id2}/delete", [], 'success', 'followups.flash.deleted');
        $check('POST', '/followups/rules', ['name' => "LiteTest H5 rule {$lang}", 'event_type' => 'DealStageChanged', 'action_configuration_json' => '{"title":"x"}', 'enabled' => '1'], 'success', 'followups.flash.rule_created');
        $rule = (int) $a->pdo->query("SELECT id FROM follow_up_rules WHERE name = 'LiteTest H5 rule {$lang}'")->fetchColumn();
        $check('POST', '/followups/rules', ['name' => '', 'event_type' => 'DealStageChanged', 'action_configuration_json' => '{}'], 'error', 'followups.error.rule_name_required');
        $check('POST', '/followups/rules', ['name' => 'x', 'event_type' => 'DealStageChanged', 'action_configuration_json' => '{bad'], 'error', 'followups.error.rule_action_json');
        $check('POST', "/followups/rules/{$rule}/toggle", [], 'success', 'followups.flash.rule_updated');
        $check('POST', "/followups/rules/{$rule}/delete", [], 'success', 'followups.flash.rule_deleted');
    }
    $a->locale('en');
    LiteAssert::same([], $problems, implode(' | ', array_slice($problems, 0, 10)));
};

$tests['H5.4 the 422 form shows the translated title error (fa) — no English left'] = function () use ($boot) {
    $a = $boot();
    $fa = require dirname(__DIR__, 2) . '/lang/fa.php';
    $a->locale('fa');
    $r = $a->request('POST', '/followups', [], ['title' => '', 'type' => 'task', 'priority' => 'normal']);
    $a->locale('en');
    LiteAssert::same(422, $r['status']);
    LiteAssert::contains(htmlspecialchars($fa['followups.error.title_required'], ENT_QUOTES), $r['body']);
    LiteAssert::notContains('Title is required.', $r['body']);
};

$tests['rollback leaves no test data behind; suite files removed'] = function () use ($boot, $h) {
    $a = $boot();
    $a->rollBack();
    foreach ($h->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE title LIKE 'LiteTest%'")->fetchColumn());
    LiteAssert::same([], array_values(array_filter($h->files, 'is_file')));
};

return $tests;
