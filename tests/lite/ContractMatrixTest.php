<?php

declare(strict_types=1);

/**
 * Step 2.10 — the access matrices, through the real router + middleware.
 *
 *  B. RBAC: every Contracts route × {org_admin, member, user without a role}.
 *  C. Module states: global off / org not entitled / user restricted /
 *     re-enabled — routes, panels, Core /records routes, sidebar.
 *  D. Tenant isolation: every route that takes an id, with another
 *     organization's contract / category / link.
 *
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use Modules\Contracts\Services\ContractService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';

$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->app = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $h->app->activateContracts();

    return $h->app;
};

$svc = fn (LocalApp $a): ContractService => $a->container()->make(ContractService::class);

/** A fresh contract owned by the org admin, in the requested status. */
$make = function (LocalApp $a, string $status) use ($svc): int {
    $c = $svc($a)->create($a->admin, ['title' => 'LiteTest matrix ' . $status, 'start_date' => '2026-01-01', 'end_date' => '2099-12-31']);
    $id = (int) $c['id'];
    match ($status) {
        'draft' => null,
        'pending' => $svc($a)->submit($a->admin, $id),
        'active' => $svc($a)->activate($a->admin, $id),
    };

    return $id;
};

/**
 * Route table: [label, method, path with {id}/{cat}/{link}, needed status, body].
 * Expected outcomes follow: route permission (RbacMiddleware), then the
 * service's record scope + status rules.
 */
$routes = [
    ['list', 'GET', '/contracts', null, []],
    ['create form', 'GET', '/contracts/create', null, []],
    ['create', 'POST', '/contracts', null, ['title' => 'LiteTest matrix new']],
    ['settings', 'GET', '/contracts/settings', null, []],
    ['save settings', 'POST', '/contracts/settings', null, []],
    ['categories', 'GET', '/contracts/categories', null, []],
    ['add category', 'POST', '/contracts/categories', null, ['name' => 'LiteTest matrix cat {n}']],
    ['rename category', 'POST', '/contracts/categories/{cat}', null, ['name' => 'LiteTest matrix renamed {n}']],
    ['delete category', 'POST', '/contracts/categories/{cat}/delete', null, []],
    ['show', 'GET', '/contracts/{id}', 'draft', []],
    ['edit form', 'GET', '/contracts/{id}/edit', 'draft', []],
    ['update', 'POST', '/contracts/{id}', 'draft', ['title' => 'LiteTest matrix updated']],
    ['delete', 'POST', '/contracts/{id}/delete', 'draft', []],
    ['submit', 'POST', '/contracts/{id}/submit', 'draft', []],
    ['return to draft', 'POST', '/contracts/{id}/return', 'pending', []],
    ['approve', 'POST', '/contracts/{id}/approve', 'pending', []],
    ['activate', 'POST', '/contracts/{id}/activate', 'draft', []],
    ['cancel', 'POST', '/contracts/{id}/cancel', 'draft', []],
    ['terminate', 'POST', '/contracts/{id}/terminate', 'active', []],
    ['renew', 'POST', '/contracts/{id}/renew', 'active', []],
    ['unlink', 'POST', '/contracts/{id}/links/{link}/delete', 'draft', []],
];

/** Expected outcome per role (member = default grants: view/create org, edit own; contract owned by the admin). */
$expected = [
    'org_admin' => array_fill_keys(array_column($routes, 0), 'ok'),
    'member' => [
        'list' => 'ok', 'create form' => 'ok', 'create' => 'ok', 'show' => 'ok',
        'settings' => 'forbidden', 'save settings' => 'forbidden', 'categories' => 'forbidden', 'add category' => 'forbidden',
        'rename category' => 'forbidden', 'delete category' => 'forbidden',
        'edit form' => 'forbidden', 'update' => 'forbidden', 'delete' => 'forbidden', 'submit' => 'forbidden',
        'return to draft' => 'forbidden', 'approve' => 'forbidden', 'activate' => 'forbidden', 'cancel' => 'forbidden',
        'terminate' => 'forbidden', 'renew' => 'forbidden', 'unlink' => 'forbidden',
    ],
    'no_role' => array_fill_keys(array_column($routes, 0), 'forbidden'),
];

$counter = 0;
$fill = function (LocalApp $a, array $route) use ($make, $svc, &$counter): array {
    [, $method, $path, $status, $body] = $route;
    $counter++;
    $id = $status !== null ? $make($a, $status) : 0;
    $cat = (int) $svc($a)->createCategory($a->admin, 'LiteTest matrix fixture ' . $counter)['id'];
    $linkId = 0;
    if (str_contains($path, '{link}')) {
        $a->pdo->prepare("INSERT INTO contract_links (organization_id, contract_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'deal', 1, 'x')")->execute([$a->orgId, $id]);
        $linkId = (int) $a->pdo->lastInsertId();
    }
    $path = str_replace(['{id}', '{cat}', '{link}'], [(string) $id, (string) $cat, (string) $linkId], $path);
    $body = array_map(fn ($v) => is_string($v) ? str_replace('{n}', (string) $counter, $v) : $v, $body);

    return [$method, $path, $body];
};

$tests = [];

// ---- B. RBAC matrix --------------------------------------------------------
foreach (['org_admin', 'member', 'no_role'] as $role) {
    $tests["B. RBAC matrix: {$role} on all " . count($routes) . ' routes'] = function () use ($boot, $role, $routes, $expected, $fill) {
        $a = $boot();
        $user = match ($role) {
            'org_admin' => $a->admin,
            'member' => $a->member ?? throw new LiteSkip('needs a second user'),
            'no_role' => $a->noRole,
        };
        $mismatches = [];
        foreach ($routes as $route) {
            $a->as($a->admin);
            [$method, $path, $body] = $fill($a, $route);
            $a->as($user);
            $got = $a->outcome($method, $path, [], $body);
            if ($got !== $expected[$role][$route[0]]) {
                $mismatches[] = "{$route[0]} ({$method} {$path}): expected {$expected[$role][$route[0]]}, got {$got}";
            }
        }
        $a->as($a->admin);
        LiteAssert::same([], $mismatches, implode(' | ', $mismatches));
    };
}

$tests['B. member is allowed on their OWN draft: edit, update, submit, cancel'] = function () use ($boot, $svc) {
    $a = $boot();
    if ($a->member === null) {
        throw new LiteSkip('needs a second user');
    }
    $id = (int) $svc($a)->create($a->member, ['title' => 'LiteTest member own matrix'])['id'];
    $a->as($a->member);
    $got = [
        $a->outcome('GET', "/contracts/{$id}/edit"),
        $a->outcome('POST', "/contracts/{$id}", [], ['title' => 'LiteTest member own matrix 2']),
        $a->outcome('POST', "/contracts/{$id}/submit"),
        $a->outcome('POST', "/contracts/{$id}/cancel"),
    ];
    $a->as($a->admin);
    LiteAssert::same(['ok', 'ok', 'ok', 'ok'], $got);
};

$tests['B. state rules hold even for org_admin (edit an active contract, terminate a draft)'] = function () use ($boot, $make) {
    $a = $boot();
    $a->as($a->admin);
    $active = $make($a, 'active');
    $draft = $make($a, 'draft');
    LiteAssert::same('forbidden', $a->outcome('GET', "/contracts/{$active}/edit"), 'no edit form for an active contract');
    // State errors redirect back with a flash (not a 4xx): the contract must be unchanged.
    $a->outcome('POST', "/contracts/{$active}", [], ['title' => 'hijack']);
    $a->outcome('POST', "/contracts/{$draft}/terminate");
    LiteAssert::true(str_starts_with((string) $a->pdo->query("SELECT title FROM contracts WHERE id = {$active}")->fetchColumn(), 'LiteTest matrix'));
    LiteAssert::same('draft', $a->pdo->query("SELECT status FROM contracts WHERE id = {$draft}")->fetchColumn());
};

$tests['B. every POST without a CSRF token is rejected (419)'] = function () use ($boot, $routes, $fill) {
    $a = $boot();
    $a->as($a->admin);
    $bad = [];
    foreach ($routes as $route) {
        if ($route[1] !== 'POST') {
            continue;
        }
        [$method, $path, $body] = $fill($a, $route);
        $body['_csrf'] = 'wrong';
        $got = $a->outcome($method, $path, [], $body);
        if ($got !== 'csrf') {
            $bad[] = "{$route[0]}: {$got}";
        }
    }
    LiteAssert::same([], $bad, implode(' | ', $bad));
};

// ---- C. Module states ------------------------------------------------------
$probe = function (LocalApp $a, int $contractId, int $contactId, ?int $dealId): array {
    $panel = fn (string $path, string $marker) => (function () use ($a, $path, $marker) {
        try {
            return str_contains($a->request('GET', $path)['body'], $marker) ? 'panel' : 'no-panel';
        } catch (\Throwable) {
            return 'page-error';
        }
    })();

    return [
        'list' => $a->outcome('GET', '/contracts'),
        'show' => $a->outcome('GET', "/contracts/{$contractId}"),
        'records-note' => $a->outcome('POST', "/records/contract/{$contractId}/notes", [], ['body' => 'probe']),
        'contact-panel' => $panel("/contacts/{$contactId}", 'data-related-panel="contracts.contact"'),
        'deal-panel' => $dealId !== null ? $panel("/deals/{$dealId}", 'data-related-panel="contracts.deal"') : 'n/a',
        'sidebar' => str_contains($a->request('GET', "/contacts/{$contactId}")['body'], 'href="/contracts"') ? 'link' : 'no-link',
    ];
};

$tests['C. module states: on → global off → org off → user restricted → re-enabled (data intact)'] = function () use ($boot, $make, $probe, $svc) {
    $a = $boot();
    $a->as($a->admin);
    $contact = (int) $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $deal = $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $deal = $deal === false ? null : (int) $deal;
    $id = $make($a, 'draft');
    $svc($a)->update($a->admin, $id, ['title' => 'LiteTest module states', 'contact_id' => (string) $contact]);
    if ($deal !== null) {
        $svc($a)->link($a->admin, $id, 'deal', $deal);
    }
    $on = ['list' => 'ok', 'show' => 'ok', 'records-note' => 'ok', 'contact-panel' => 'panel', 'deal-panel' => $deal !== null ? 'panel' : 'n/a', 'sidebar' => 'link'];
    $off = ['list' => 'notfound', 'show' => 'notfound', 'records-note' => 'notfound', 'contact-panel' => 'no-panel', 'deal-panel' => $deal !== null ? 'no-panel' : 'n/a', 'sidebar' => 'no-link'];

    LiteAssert::same($on, $probe($a, $id, $contact, $deal), 'enabled');

    $a->setModuleStatus('contracts', 'disabled');
    LiteAssert::same($off, $probe($a, $id, $contact, $deal), 'globally disabled');
    $a->setModuleStatus('contracts', 'active');

    $a->setOrgEntitled('contracts', false);
    LiteAssert::same($off, $probe($a, $id, $contact, $deal), 'organization not entitled');
    $a->setOrgEntitled('contracts', true);

    if ($a->member !== null) {
        $a->setUserOverride((int) $a->member['id'], 'contracts', false);
        $a->as($a->member);
        LiteAssert::same($off, $probe($a, $id, $contact, $deal), 'member restricted');
        $a->as($a->admin);
        LiteAssert::same($on, $probe($a, $id, $contact, $deal), 'admin unaffected by the member restriction');
        $a->setUserOverride((int) $a->member['id'], 'contracts', null);
    }

    LiteAssert::same($on, $probe($a, $id, $contact, $deal), 're-enabled');
    LiteAssert::same('LiteTest module states', $svc($a)->find($a->admin, $id)['title'], 'contract intact');
    LiteAssert::same($deal !== null ? 1 : 0, count($svc($a)->links($a->admin, $id)), 'links intact');
};

$tests['C. Sales off: Contracts keeps working; deal links become plain text; deal features hidden'] = function () use ($boot, $make, $svc) {
    $a = $boot();
    $a->as($a->admin);
    $deal = $a->pdo->query("SELECT id FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    if ($deal === false) {
        throw new LiteSkip('needs a deal');
    }
    $id = $make($a, 'draft');
    $svc($a)->link($a->admin, $id, 'deal', (int) $deal);
    $a->setOrgEntitled('sales', false);
    try {
        LiteAssert::same('ok', $a->outcome('GET', '/contracts'));
        $body = $a->request('GET', "/contracts/{$id}")['body'];
        LiteAssert::notContains('href="/deals/', $body);
        LiteAssert::same('notfound', $a->outcome('GET', '/contracts/create', ['from_deal' => (string) $deal]));
        LiteAssert::same('notfound', $a->outcome('GET', "/deals/{$deal}"), 'Sales itself is gated as before');
    } finally {
        $a->setOrgEntitled('sales', true);
    }
};

// ---- D. Tenant isolation ---------------------------------------------------
$tests['D. tenant isolation: every id route 404s for another organization\'s contract/category/link'] = function () use ($boot, $routes) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $insertContract = function (string $status) use ($a): int {
        $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id, status, start_date) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$a->otherOrgId, 'LiteTest foreign ' . $status, (int) $a->admin['id'], (int) $a->admin['id'], $status, '2026-01-01']);

        return (int) $a->pdo->lastInsertId();
    };
    $foreign = ['draft' => $insertContract('draft'), 'pending' => $insertContract('pending_approval'), 'active' => $insertContract('active')];
    $a->pdo->prepare('INSERT INTO contract_categories (organization_id, name) VALUES (?, ?)')->execute([$a->otherOrgId, 'LiteTest foreign cat']);
    $foreignCat = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO contract_links (organization_id, contract_id, linked_type, linked_id, linked_label) VALUES (?, ?, 'deal', 1, 'x')")->execute([$a->otherOrgId, $foreign['draft']]);
    $foreignLink = (int) $a->pdo->lastInsertId();

    $leaks = [];
    foreach ($routes as [$label, $method, $path, $status, $body]) {
        if (!str_contains($path, '{id}') && !str_contains($path, '{cat}')) {
            continue;
        }
        $p = str_replace(['{id}', '{cat}', '{link}'], [(string) ($foreign[$status ?? 'draft']), (string) $foreignCat, (string) $foreignLink], $path);
        $body = array_map(fn ($v) => is_string($v) ? str_replace('{n}', 'x', $v) : $v, $body);
        $got = $a->outcome($method, $p, [], $body);
        if ($got !== 'notfound') {
            $leaks[] = "{$label} ({$method} {$p}): {$got}";
        }
    }
    LiteAssert::same([], $leaks, implode(' | ', $leaks));

    // Nothing in the other organization changed.
    $rows = $a->pdo->query('SELECT status, title FROM contracts WHERE id IN (' . implode(',', $foreign) . ') ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    LiteAssert::same(['draft', 'pending_approval', 'active'], array_column($rows, 'status'));
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM contract_categories WHERE id = {$foreignCat} AND name = 'LiteTest foreign cat'")->fetchColumn());
    LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM contract_links WHERE id = {$foreignLink}")->fetchColumn());
};

$tests['D. tenant isolation: lists, search, panels and lookups never show the other organization'] = function () use ($boot, $svc) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG secret', (int) $a->admin['id'], (int) $a->admin['id']]);
    $foreign = (int) $a->pdo->lastInsertId();

    LiteAssert::notContains('LiteTest XORG secret', $a->request('GET', '/contracts', ['q' => 'XORG'])['body']);
    LiteAssert::same([], $svc($a)->list($a->admin, ['search' => 'XORG']));
    LiteAssert::same(0, $svc($a)->count($a->admin, ['search' => 'XORG']));
    LiteAssert::same(null, $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class)->find('contract', $foreign, $a->admin));
    LiteAssert::same([], $svc($a)->linkedTo($a->admin, 'deal', 1, null) === [] ? [] : array_filter($svc($a)->linkedTo($a->admin, 'deal', 1, null), fn ($c) => (int) $c['id'] === $foreign));
};

$tests['D. Core /records routes on another organization\'s contract write nothing there'] = function () use ($boot) {
    $a = $boot();
    if ($a->otherOrgId === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $a->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')->execute([$a->otherOrgId, 'LiteTest XORG notes', (int) $a->admin['id'], (int) $a->admin['id']]);
    $foreign = (int) $a->pdo->lastInsertId();
    $a->outcome('POST', "/records/contract/{$foreign}/notes", [], ['body' => 'cross-tenant probe']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'contract' AND notable_id = {$foreign} AND organization_id = {$a->otherOrgId}")->fetchColumn(), 'no note in the other organization');
};

$tests['rollback leaves no test data behind'] = function () use ($boot) {
    $a = $boot();
    $a->rollBack();
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM contract_categories')->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'contracts'")->fetchColumn());
    LiteAssert::same('active', $a->pdo->query("SELECT status FROM modules WHERE `key` = 'sales'")->fetchColumn());
};

return $tests;
