<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Projects permission matrix + organization isolation,
 * through the real router + middleware.
 *
 * B. every route × {org_admin, member, user without a role}
 * D. organization isolation: every id route with another organization's
 *    project / member / link → 404 and nothing changed there; lists,
 *    search, registry lookups and the Core /records routes.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Infrastructure\Records\RecordTypeRegistry;
use Tests\Lite\Support\ProjectsLite;

$boot = ProjectsLite::booter(true);
$svc = fn ($a) => ProjectsLite::service($a);

/**
 * [label, method, path, body, expected for admin, member, no role].
 * {id} = a draft project managed by the org admin (fresh per check),
 * {member} = a member row of it, {link} = a link row of it.
 * Member: view/create at organization scope, edit + manage_members own only.
 */
$routes = [
    ['list', 'GET', '/projects', [], 'ok', 'ok', 'forbidden'],
    ['create form', 'GET', '/projects/create', [], 'ok', 'ok', 'forbidden'],
    ['create', 'POST', '/projects', ['name' => 'LiteTest matrix new'], 'ok', 'ok', 'forbidden'],
    ['settings', 'GET', '/projects/settings', [], 'ok', 'forbidden', 'forbidden'],
    ['save settings', 'POST', '/projects/settings', [], 'ok', 'forbidden', 'forbidden'],
    ['show', 'GET', '/projects/{id}', [], 'ok', 'ok', 'forbidden'],
    ['edit form', 'GET', '/projects/{id}/edit', [], 'ok', 'forbidden', 'forbidden'],
    ['update', 'POST', '/projects/{id}', ['name' => 'LiteTest matrix upd'], 'ok', 'forbidden', 'forbidden'],
    ['status', 'POST', '/projects/{id}/status', ['action' => 'start'], 'ok', 'forbidden', 'forbidden'],
    ['add member', 'POST', '/projects/{id}/members', ['user_id' => '{memberUser}'], 'ok', 'forbidden', 'forbidden'],
    ['remove member', 'POST', '/projects/{id}/members/{member}/delete', [], 'ok', 'forbidden', 'forbidden'],
    ['link existing', 'POST', '/projects/links', ['project_id' => '{id}', 'linked_type' => 'contract', 'linked_id' => '{contract}'], 'ok', 'forbidden', 'forbidden'],
    ['unlink', 'POST', '/projects/{id}/links/{link}/delete', [], 'ok', 'forbidden', 'forbidden'],
    ['delete', 'POST', '/projects/{id}/delete', [], 'ok', 'forbidden', 'forbidden'],
];

$fill = function ($a, string $path, array $body) use ($svc): array {
    $p = $svc($a)->create($a->admin, ['name' => 'LiteTest matrix target']);
    $member = $svc($a)->addMember($a->admin, (int) $p['id'], (int) $a->member['id']);
    $contract = $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, ['title' => 'LiteTest matrix contract']);
    $link = $svc($a)->link($a->admin, (int) $p['id'], 'contract', (int) $contract['id']);
    $other = $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, ['title' => 'LiteTest matrix contract 2']);
    $map = ['{id}' => (string) $p['id'], '{member}' => (string) $member['id'], '{memberUser}' => (string) $a->member['id'],
        '{link}' => (string) $link['id'], '{contract}' => (string) $other['id']];

    return [strtr($path, $map), array_map(fn ($v) => is_string($v) ? strtr($v, $map) : $v, $body)];
};

$tests = [];
foreach (['admin' => 4, 'member' => 5, 'noRole' => 6] as $role => $column) {
    $tests["B. RBAC matrix: {$role} on all " . count($routes) . ' routes'] = function () use ($boot, $routes, $role, $column, $fill) {
        $a = $boot();
        $wrong = [];
        foreach ($routes as $route) {
            [$label, $method, $path, $body] = $route;
            $a->as($a->admin);
            [$p, $b] = $fill($a, $path, $body);
            $a->as($role === 'admin' ? $a->admin : ($role === 'member' ? $a->member : $a->noRole));
            $got = $a->outcome($method, $p, [], $b);
            if ($got !== $route[$column]) {
                $wrong[] = "{$label}: expected {$route[$column]}, got {$got}";
            }
        }
        $a->as($a->admin);
        LiteAssert::same([], $wrong, implode(' | ', $wrong));
    };
}

$tests['B. member manages their OWN project: edit, status, members — but still no delete'] = function () use ($boot, $svc) {
    $a = $boot();
    $a->as($a->member);
    $p = $svc($a)->create($a->member, ['name' => 'LiteTest matrix member own']);
    $id = (int) $p['id'];
    LiteAssert::same('ok', $a->outcome('GET', "/projects/{$id}/edit"));
    LiteAssert::same('ok', $a->outcome('POST', "/projects/{$id}", [], ['name' => 'LiteTest matrix member own 2']));
    LiteAssert::same('ok', $a->outcome('POST', "/projects/{$id}/members", [], ['user_id' => (string) $a->admin['id']]));
    LiteAssert::same('ok', $a->outcome('POST', "/projects/{$id}/status", [], ['action' => 'plan']));
    LiteAssert::same('planned', $a->pdo->query("SELECT status FROM projects WHERE id = {$id}")->fetchColumn());
    LiteAssert::same('forbidden', $a->outcome('POST', "/projects/{$id}/delete"));
    $a->as($a->admin);
};

$tests['B. a user restricted from the module gets 404 (never 403) everywhere'] = function () use ($boot, $routes, $fill) {
    $a = $boot();
    $a->setUserOverride((int) $a->member['id'], 'projects', false);
    try {
        $wrong = [];
        foreach ($routes as [$label, $method, $path, $body]) {
            $a->as($a->admin);
            [$p, $b] = $fill($a, $path, $body);
            $a->as($a->member);
            $got = $a->outcome($method, $p, [], $b);
            if ($got !== 'notfound') {
                $wrong[] = "{$label}: {$got}";
            }
        }
        LiteAssert::same([], $wrong, implode(' | ', $wrong));
    } finally {
        $a->setUserOverride((int) $a->member['id'], 'projects', null);
        $a->as($a->admin);
    }
};

// ---- D. Organization isolation -------------------------------------------
$tests['D. every id route 404s for another organization\'s project / member; nothing changed there'] = function () use ($boot, $routes) {
    $a = $boot();
    $foreign = ProjectsLite::foreignProject($a);
    if ($foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $foreignOwner = (int) $a->pdo->query("SELECT owner_id FROM projects WHERE id = {$foreign}")->fetchColumn();
    $a->pdo->prepare('INSERT INTO project_members (organization_id, project_id, user_id) VALUES (?, ?, ?)')->execute([$a->otherOrgId, $foreign, $foreignOwner]);
    $foreignMember = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO project_links (organization_id, project_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'deal', 1, 'x')")->execute([$a->otherOrgId, $foreign]);
    $foreignLink = (int) $a->pdo->lastInsertId();
    $myContract = $a->container()->make(\Modules\Contracts\Services\ContractService::class)->create($a->admin, ['title' => 'LiteTest matrix iso contract']);

    $leaks = [];
    foreach ($routes as [$label, $method, $path, $body]) {
        if (!str_contains($path, '{id}') && !in_array('{id}', $body, true)) {
            continue;
        }
        $map = ['{id}' => (string) $foreign, '{member}' => (string) $foreignMember, '{link}' => (string) $foreignLink,
            '{memberUser}' => (string) $a->member['id'], '{contract}' => (string) $myContract['id']];
        $got = $a->outcome($method, strtr($path, $map), [], array_map(fn ($v) => is_string($v) ? strtr($v, $map) : $v, $body));
        if ($got !== 'notfound') {
            $leaks[] = "{$label}: {$got}";
        }
    }
    LiteAssert::same([], $leaks, implode(' | ', $leaks));

    $row = $a->pdo->query("SELECT name, status FROM projects WHERE id = {$foreign}")->fetch(\PDO::FETCH_ASSOC);
    LiteAssert::same(['name' => 'XORG project', 'status' => 'active'], $row, 'foreign project untouched');
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_members WHERE project_id = {$foreign}")->fetchColumn(), 'foreign member untouched');
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE project_id = {$foreign}")->fetchColumn(), 'foreign link untouched, nothing added');

    // My own project + another organization's link id → 404, nothing removed.
    $mine = (int) ProjectsLite::service($a)->create($a->admin, ['name' => 'LiteTest matrix iso mine'])['id'];
    LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$mine}/links/{$foreignLink}/delete"));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_links WHERE id = {$foreignLink}")->fetchColumn());
};

$tests['D. my project + another organization\'s member id: remove → 404, nothing removed'] = function () use ($boot, $svc) {
    $a = $boot();
    $foreign = ProjectsLite::foreignProject($a);
    if ($foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $foreignOwner = (int) $a->pdo->query("SELECT owner_id FROM projects WHERE id = {$foreign}")->fetchColumn();
    $a->pdo->prepare('INSERT INTO project_members (organization_id, project_id, user_id) VALUES (?, ?, ?)')->execute([$a->otherOrgId, $foreign, $foreignOwner]);
    $foreignMember = (int) $a->pdo->lastInsertId();
    $mine = (int) $svc($a)->create($a->admin, ['name' => 'LiteTest matrix mine'])['id'];
    LiteAssert::same('notfound', $a->outcome('POST', "/projects/{$mine}/members/{$foreignMember}/delete"));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM project_members WHERE id = {$foreignMember}")->fetchColumn());
};

$tests['D. lists, search, "mine", registry lookups never show the other organization'] = function () use ($boot, $svc) {
    $a = $boot();
    $foreign = ProjectsLite::foreignProject($a);
    if ($foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    // Even a membership row naming this user can't pull the foreign project in.
    $a->pdo->prepare('INSERT INTO project_members (organization_id, project_id, user_id) VALUES (?, ?, ?)')->execute([$a->otherOrgId, $foreign, (int) $a->admin['id']]);
    LiteAssert::notContains('XORG project', $a->request('GET', '/projects', ['q' => 'XORG'])['body']);
    LiteAssert::same([], $svc($a)->list($a->admin, ['search' => 'XORG']));
    LiteAssert::same(0, $svc($a)->count($a->admin, ['search' => 'XORG', 'involving_user_id' => (int) $a->admin['id']]));
    LiteAssert::same(null, $a->container()->make(RecordTypeRegistry::class)->find('project', $foreign, $a->admin));
    $a->pdo->prepare("UPDATE role_permissions SET scope = 'own' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
    try {
        $a->pdo->prepare('INSERT INTO project_members (organization_id, project_id, user_id) VALUES (?, ?, ?)')->execute([$a->otherOrgId, $foreign, (int) $a->member['id']]);
        LiteAssert::same(0, $svc($a)->count($a->member, ['search' => 'XORG']), 'own scope + foreign membership row');
    } finally {
        $a->pdo->prepare("UPDATE role_permissions SET scope = 'organization' WHERE role_id = ? AND permission_key = 'projects.view'")->execute([$a->memberRoleId]);
    }
};

$tests['D. Core /records routes on another organization\'s project write nothing there'] = function () use ($boot) {
    $a = $boot();
    $foreign = ProjectsLite::foreignProject($a);
    if ($foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $a->outcome('POST', "/records/project/{$foreign}/notes", [], ['body' => 'cross-tenant probe']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'project' AND notable_id = {$foreign} AND organization_id = {$a->otherOrgId}")->fetchColumn());
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest%' OR code LIKE 'XORG-%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'projects'")->fetchColumn());
};

return $tests;
