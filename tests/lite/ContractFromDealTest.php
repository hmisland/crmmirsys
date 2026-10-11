<?php

declare(strict_types=1);

/**
 * Step 2.8 — manual "Create contract" from a deal page, end to end
 * through the real router + middleware. crm_local only, one
 * transaction, rolled back.
 */

use App\Core\Application;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\Services\ContractService;

$db = new \stdClass();
$db->ready = false;
$db->reason = '';

$setup = function () use ($db): void {
    if ($db->ready) {
        return;
    }
    if ($db->reason !== '') {
        throw new LiteSkip($db->reason);
    }

    try {
        $app = new Application(dirname(__DIR__, 2));
        $app->loadRoutes();
        restore_error_handler();
        restore_exception_handler();

        $pdo = Connection::pdo();
        if (Config::get('app.env') !== 'local' || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'crm_local') {
            throw new \RuntimeException('not the local crm_local database');
        }
        $adminId = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active' ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($adminId === false) {
            throw new \RuntimeException('no active org_admin user');
        }
    } catch (\Throwable $e) {
        $db->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($db->reason);
    }

    $users = new UserRepository();
    $admin = $users->findById((int) $adminId);
    $orgId = (int) $admin['organization_id'];
    $deal = $pdo->query("SELECT * FROM deals WHERE organization_id = {$orgId} ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($deal === false) {
        $db->reason = 'needs a deal in the organization';
        throw new LiteSkip($db->reason);
    }

    CurrentUser::set($admin);
    OrganizationContext::set($orgId);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);
    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, (int) $pdo->lastInsertId()]);

    // A deal in another organization, for tampering tests.
    $other = $pdo->query("SELECT id FROM organizations WHERE id <> {$orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $db->foreignDeal = null;
    if ($other !== false) {
        $pdo->prepare('INSERT INTO pipelines (organization_id, name) VALUES (?, ?)')->execute([(int) $other, 'LiteTest p']);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO pipeline_stages (organization_id, pipeline_id, name) VALUES (?, ?, ?)')->execute([(int) $other, $pid, 'LiteTest s']);
        $sid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO deals (organization_id, pipeline_id, stage_id, name) VALUES (?, ?, ?, ?)')->execute([(int) $other, $pid, $sid, 'LiteTest foreign deal']);
        $db->foreignDeal = (int) $pdo->lastInsertId();
    }

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->orgId = $orgId;
    $db->deal = $deal;
    $db->salesModuleId = (int) $pdo->query("SELECT id FROM modules WHERE `key` = 'sales'")->fetchColumn();
    $db->memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
    $otherUser = $pdo->query("SELECT id FROM users WHERE organization_id = {$orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $db->member = $otherUser === false ? null : array_merge($users->findById((int) $otherUser), ['role_id' => $db->memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null]);
    $db->ready = true;
};

$as = function (array $user) use ($db): void {
    CurrentUser::set($user);
    $db->c->make(ModuleGate::class)->reset();
};
$read = fn (Response $r, string $prop) => (new \ReflectionProperty(Response::class, $prop))->getValue($r);
$get = function (string $path, array $query = []) use ($db, $read): string {
    $db->c->make(ModuleGate::class)->reset();
    $response = $db->app->router->dispatch(new Request('GET', $path, $query, [], [], []));
    LiteAssert::same(200, $read($response, 'status'), "status of {$path}");

    return (string) $read($response, 'body');
};
$post = function (string $path, array $body) use ($db, $read): array {
    $db->c->make(ModuleGate::class)->reset();
    $body['_csrf'] = Csrf::token();
    $response = $db->app->router->dispatch(new Request('POST', $path, [], $body, [], []));

    return [$read($response, 'status'), $read($response, 'headers')['Location'] ?? null, (string) $read($response, 'body')];
};
$salesEntitled = function (bool $on) use ($db): void {
    $db->pdo->prepare('UPDATE organization_modules SET enabled = ? WHERE organization_id = ? AND module_id = ?')->execute([$on ? 1 : 0, $db->orgId, $db->salesModuleId]);
};
$contractCount = fn () => (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%' OR title = " . $db->pdo->quote((string) $db->deal['name']))->fetchColumn();

return [
    'deal panel offers "New contract" → /contracts/create?from_deal=ID' => function () use ($setup, $db, $as, $get) {
        $setup();
        $as($db->admin);
        $body = $get('/deals/' . $db->deal['id']);
        LiteAssert::contains('href="/contracts/create?from_deal=' . $db->deal['id'] . '"', $body);
    },

    'the form is prefilled from the deal and carries the source' => function () use ($setup, $db, $as, $get) {
        $setup();
        $as($db->admin);
        $body = $get('/contracts/create', ['from_deal' => (string) $db->deal['id']]);
        LiteAssert::contains('value="' . htmlspecialchars((string) $db->deal['name'], ENT_QUOTES) . '"', $body, 'title from deal name');
        LiteAssert::contains('name="source_type" value="deal"', $body);
        LiteAssert::contains('name="source_id" value="' . $db->deal['id'] . '"', $body);
        LiteAssert::contains('href="/deals/' . $db->deal['id'] . '"', $body, 'notice links to the deal');
        if (!empty($db->deal['contact_id'])) {
            LiteAssert::contains('value="' . $db->deal['contact_id'] . '" selected', $body, 'contact preselected');
        }
        $currencyId = $db->pdo->query('SELECT id FROM currencies WHERE is_active = 1 AND code = ' . $db->pdo->quote((string) $db->deal['currency_code']))->fetchColumn();
        if ($currencyId !== false) {
            LiteAssert::contains('value="' . $currencyId . '" selected', $body, 'currency mapped from the deal code');
        }
    },

    'deal not available → 404: unknown, another organization, Sales disabled' => function () use ($setup, $db, $as, $get, $salesEntitled) {
        $setup();
        $as($db->admin);
        LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts/create', ['from_deal' => '99999999']));
        if ($db->foreignDeal !== null) {
            LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts/create', ['from_deal' => (string) $db->foreignDeal]));
        }
        $salesEntitled(false);
        try {
            LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts/create', ['from_deal' => (string) $db->deal['id']]));
            $get('/contracts/create'); // a plain contract still works without Sales
        } finally {
            $salesEntitled(true);
        }
    },

    'save: contract created AND linked to the deal; shows on the deal panel' => function () use ($setup, $db, $as, $get, $post) {
        $setup();
        $as($db->admin);
        [$status, $location] = $post('/contracts', ['title' => 'LiteTest from deal', 'source_type' => 'deal', 'source_id' => (string) $db->deal['id']]);
        LiteAssert::same(302, $status);
        $id = (int) preg_replace('#^/contracts/(\d+)$#', '$1', (string) $location);
        $db->created = $id;

        $links = $db->c->make(ContractService::class)->links($db->admin, $id);
        LiteAssert::same(1, count($links));
        LiteAssert::same('deal', $links[0]['type']);
        LiteAssert::same((int) $db->deal['id'], $links[0]['record_id']);
        LiteAssert::true(in_array('linked', array_column((new TimelineEventRepository())->forSubject('contract', $id), 'type'), true));

        LiteAssert::contains('href="/contracts/' . $id . '"', $get('/deals/' . $db->deal['id']));
    },

    'validation error keeps the source (re-derived on the server) and creates nothing' => function () use ($setup, $db, $as, $post, $contractCount) {
        $setup();
        $as($db->admin);
        $before = $contractCount();
        [$status, , $body] = $post('/contracts', ['title' => '', 'source_type' => 'deal', 'source_id' => (string) $db->deal['id'], 'source_label' => '<b>forged</b>']);
        LiteAssert::same(422, $status);
        LiteAssert::contains('name="source_id" value="' . $db->deal['id'] . '"', $body);
        LiteAssert::notContains('forged', $body, 'label never taken from the form');
        LiteAssert::same($before, $contractCount());
    },

    'tampered source (another organization\'s deal, unknown type) → 422, nothing created' => function () use ($setup, $db, $as, $post, $contractCount) {
        $setup();
        $as($db->admin);
        $before = $contractCount();
        $cases = [['nope', '1'], ['contract', (string) ($db->created ?? 1)], ['deal', '99999999']];
        if ($db->foreignDeal !== null) {
            $cases[] = ['deal', (string) $db->foreignDeal];
        }
        foreach ($cases as [$type, $id]) {
            [$status, , $body] = $post('/contracts', ['title' => 'LiteTest tampered', 'source_type' => $type, 'source_id' => $id]);
            LiteAssert::same(422, $status, "{$type} #{$id}");
            LiteAssert::contains('alert--error', $body);
        }
        LiteAssert::same($before, $contractCount());
    },

    'member (create + edit own): can create from a deal they can see' => function () use ($setup, $db, $as, $post) {
        $setup();
        if ($db->member === null) {
            throw new LiteSkip('needs a second user');
        }
        $as($db->member);
        try {
            [$status, $location] = $post('/contracts', ['title' => 'LiteTest member from deal', 'source_type' => 'deal', 'source_id' => (string) $db->deal['id']]);
            LiteAssert::same(302, $status);
            $id = (int) preg_replace('#^/contracts/(\d+)$#', '$1', (string) $location);
            LiteAssert::same(1, count($db->c->make(ContractService::class)->links($db->member, $id)));
        } finally {
            $as($db->admin);
        }
    },

    'without contracts.create: no button on the deal panel' => function () use ($setup, $db, $as, $get) {
        $setup();
        if ($db->member === null) {
            throw new LiteSkip('needs a second user');
        }
        $db->pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_key = 'contracts.create'")->execute([$db->memberRoleId]);
        $as($db->member);
        try {
            LiteAssert::notContains('/contracts/create?from_deal=', $get('/deals/' . $db->deal['id']));
        } finally {
            $db->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'contracts.create', 'organization')")->execute([$db->memberRoleId]);
            $as($db->admin);
        }
    },

    'rollback leaves no test data behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query('SELECT COUNT(*) FROM contract_links')->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM deals WHERE name LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(1, (int) $db->pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.`key` = 'member' AND rp.permission_key = 'contracts.create'")->fetchColumn());
    },
];
