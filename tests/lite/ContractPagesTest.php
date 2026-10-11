<?php

declare(strict_types=1);

/**
 * Step 2.5 — contract pages, end to end through the real router and
 * middleware (Auth → ModuleMiddleware → RbacMiddleware → Csrf).
 * crm_local only, one transaction, rolled back. Assertions use markup
 * (URLs, form actions, numbers), not translated text.
 */

use App\Core\Application;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\NoteRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;

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
    $memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
    $otherId = $pdo->query("SELECT id FROM users WHERE organization_id = {$orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    if ($otherId === false) {
        $db->reason = 'needs a second active user in the organization';
        throw new LiteSkip($db->reason);
    }

    CurrentUser::set($admin);
    OrganizationContext::set($orgId);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);
    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->member = array_merge($users->findById((int) $otherId), ['role_id' => $memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null]);
    $db->orgId = $orgId;
    $db->currencyId = (int) $pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $db->otherOrgId = $pdo->query("SELECT id FROM organizations WHERE id <> {$orgId} ORDER BY id LIMIT 1")->fetchColumn();
    $db->ready = true;
};

$as = function (array $user) use ($db): void {
    CurrentUser::set($user);
    $db->c->make(ModuleGate::class)->reset();
};

$read = fn (Response $r, string $prop) => (new \ReflectionProperty(Response::class, $prop))->getValue($r);

$get = function (string $path, array $query = []) use ($db, $read): array {
    $db->c->make(ModuleGate::class)->reset();
    $response = $db->app->router->dispatch(new Request('GET', $path, $query, [], [], []));

    return [$read($response, 'status'), (string) $read($response, 'body')];
};

/** POST with a valid CSRF token unless $csrf = false. Returns [status, Location, body]. */
$post = function (string $path, array $body = [], bool $csrf = true) use ($db, $read): array {
    $db->c->make(ModuleGate::class)->reset();
    if ($csrf) {
        $body['_csrf'] = Csrf::token();
    }
    $response = $db->app->router->dispatch(new Request('POST', $path, [], $body, [], []));

    return [$read($response, 'status'), $read($response, 'headers')['Location'] ?? null, (string) $read($response, 'body')];
};

$activateModule = function () use ($db): void {
    if (!empty($db->moduleId)) {
        return;
    }
    $db->pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $db->moduleId = (int) $db->pdo->lastInsertId();
    $db->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$db->orgId, $db->moduleId]);
};

$idFrom = fn (?string $location): int => (int) preg_replace('#^/contracts/(\d+)$#', '$1', (string) $location);

return [
    'module not activated: every contract route 404s and the sidebar has no link' => function () use ($setup, $db, $as, $get, $post) {
        $setup();
        $as($db->admin);
        LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts'));
        LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts/create'));
        LiteAssert::throws(NotFoundException::class, fn () => $post('/contracts', ['title' => 'x']));

        [$status, $body] = $get('/leads');
        LiteAssert::same(200, $status);
        LiteAssert::notContains('href="/contracts"', $body);
    },

    'activated: list renders with the sidebar link, actions and filters' => function () use ($setup, $db, $as, $get, $activateModule) {
        $setup();
        $activateModule();
        $as($db->admin);
        [$status, $body] = $get('/contracts');
        LiteAssert::same(200, $status);
        LiteAssert::contains('href="/contracts"', $body, 'sidebar link');
        LiteAssert::contains('href="/contracts/create"', $body);
        LiteAssert::contains('href="/contracts/categories"', $body);
        LiteAssert::contains('name="expiring"', $body);
    },

    'create: invalid input re-renders the form (422) with errors; valid input redirects to the contract' => function () use ($setup, $db, $as, $get, $post, $activateModule, $idFrom) {
        $setup();
        $activateModule();
        $as($db->admin);

        [$status, , $body] = $post('/contracts', ['title' => '', 'start_date' => '2026-05-01', 'end_date' => '2026-04-01']);
        LiteAssert::same(422, $status);
        LiteAssert::contains('class="field-error"', $body);
        LiteAssert::contains('value="2026-05-01"', $body, 'input kept');

        [$status, $location] = $post('/contracts', ['title' => 'LiteTest page contract', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'value' => '1500000', 'currency_id' => (string) $db->currencyId]);
        LiteAssert::same(302, $status);
        LiteAssert::same(1, preg_match('#^/contracts/\d+$#', (string) $location));
        $db->contractId = $idFrom($location);
    },

    'show and edit pages render; draft shows edit, submit, activate and record-support forms' => function () use ($setup, $db, $as, $get) {
        $setup();
        $as($db->admin);
        $id = $db->contractId ?? throw new LiteSkip('depends on the create test');

        [$status, $body] = $get('/contracts/' . $id);
        LiteAssert::same(200, $status);
        LiteAssert::contains('LiteTest page contract', $body);
        foreach (["/contracts/{$id}/edit", "/contracts/{$id}/submit", "/contracts/{$id}/activate", "/contracts/{$id}/cancel", "/contracts/{$id}/delete", "/records/contract/{$id}/notes", "/records/contract/{$id}/tags", "/records/contract/{$id}/attachments"] as $url) {
            LiteAssert::contains('"' . $url . '"', $body, $url);
        }
        LiteAssert::notContains("/contracts/{$id}/terminate", $body, 'no terminate on a draft');

        [$status, $body] = $get("/contracts/{$id}/edit");
        LiteAssert::same(200, $status);
        LiteAssert::contains('action="/contracts/' . $id . '"', $body);
    },

    'update, then activate: number shown, editing and deleting gone, terminate and renew offered' => function () use ($setup, $db, $as, $get, $post) {
        $setup();
        $as($db->admin);
        $id = $db->contractId ?? throw new LiteSkip('depends on the create test');

        [$status, $location] = $post("/contracts/{$id}", ['title' => 'LiteTest page contract v2', 'start_date' => '2026-01-01', 'end_date' => '2099-12-31']);
        LiteAssert::same(302, $status);
        LiteAssert::same("/contracts/{$id}", $location);

        [$status, $location] = $post("/contracts/{$id}/activate", ['signed_on' => '2026-01-01']);
        LiteAssert::same("/contracts/{$id}", $location);

        [, $body] = $get('/contracts/' . $id);
        LiteAssert::same(1, preg_match('/CTR-\d{4,}/', $body), 'contract number shown');
        LiteAssert::notContains("/contracts/{$id}/edit", $body);
        LiteAssert::notContains("/contracts/{$id}/delete", $body);
        LiteAssert::contains("/contracts/{$id}/terminate", $body);
        LiteAssert::contains("/contracts/{$id}/renew", $body);
        LiteAssert::throws(AuthorizationException::class, fn () => $get("/contracts/{$id}/edit"), 'no edit form for an active contract');
    },

    'list filters: status, search' => function () use ($setup, $db, $as, $get) {
        $setup();
        $as($db->admin);
        [, $active] = $get('/contracts', ['status' => 'active', 'q' => 'LiteTest page']);
        [, $drafts] = $get('/contracts', ['status' => 'draft', 'q' => 'LiteTest page']);
        LiteAssert::contains('LiteTest page contract v2', $active);
        LiteAssert::notContains('LiteTest page contract v2', $drafts);
    },

    'notes on a contract go through the Core /records routes' => function () use ($setup, $db, $as, $post) {
        $setup();
        $as($db->admin);
        $id = $db->contractId ?? throw new LiteSkip('depends on the create test');
        [$status, $location] = $post("/records/contract/{$id}/notes", ['body' => 'LiteTest note via route']);
        LiteAssert::same("/contracts/{$id}", $location);
        LiteAssert::same(1, count((new NoteRepository())->forSubject('contract', $id)));
    },

    'related-panel slot on the contract page' => function () use ($setup, $db, $as, $get) {
        $setup();
        $as($db->admin);
        $id = $db->contractId ?? throw new LiteSkip('depends on the create test');
        $db->c->make(RelatedPanelRegistry::class)->register(new RelatedPanel('contract', 'lite.contract', null, 'common.notes', fn (array $s) => '<p>LITE-CONTRACT-PANEL-' . $s['id'] . '</p>'));
        [, $body] = $get('/contracts/' . $id);
        LiteAssert::contains('LITE-CONTRACT-PANEL-' . $id, $body);
    },

    'categories page: org admin manages; member is refused' => function () use ($setup, $db, $as, $get, $post) {
        $setup();
        $as($db->admin);
        [$status] = $get('/contracts/categories');
        LiteAssert::same(200, $status);
        [, $location] = $post('/contracts/categories', ['name' => 'LiteTest Category']);
        LiteAssert::same('/contracts/categories', $location);
        [, $body] = $get('/contracts/categories');
        LiteAssert::contains('LiteTest Category', $body);

        $as($db->member);
        LiteAssert::throws(AuthorizationException::class, fn () => $get('/contracts/categories'));
        $as($db->admin);
    },

    'member: can list and create, cannot activate or edit someone else\'s contract' => function () use ($setup, $db, $as, $get, $post) {
        $setup();
        $id = $db->contractId ?? throw new LiteSkip('depends on the create test');
        $draft = $db->c->make(\Modules\Contracts\Services\ContractService::class)->create($db->admin, ['title' => 'LiteTest admin draft']);

        $as($db->member);
        [$status, $body] = $get('/contracts');
        LiteAssert::same(200, $status);
        LiteAssert::notContains('href="/contracts/categories"', $body, 'no settings button for member');

        [$status] = $post('/contracts', ['title' => 'LiteTest member page']);
        LiteAssert::same(302, $status);

        LiteAssert::throws(AuthorizationException::class, fn () => $post('/contracts/' . $draft['id'] . '/activate'), 'route-level: no contracts.activate');
        LiteAssert::throws(AuthorizationException::class, fn () => $get('/contracts/' . $draft['id'] . '/edit'), 'record-level: not the owner');
        $as($db->admin);
    },

    'POST without a CSRF token is rejected (419)' => function () use ($setup, $db, $as, $post) {
        $setup();
        $as($db->admin);
        try {
            $post('/contracts', ['title' => 'LiteTest no csrf'], false);
            throw new LiteAssertionFailed('expected HttpException');
        } catch (HttpException $e) {
            LiteAssert::same(419, $e->statusCode);
        }
    },

    'tenant: another organization\'s contract id 404s' => function () use ($setup, $db, $as, $get) {
        $setup();
        if ($db->otherOrgId === false) {
            throw new LiteSkip('only one organization');
        }
        $db->pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute([(int) $db->otherOrgId, 'LiteTest foreign page', (int) $db->admin['id'], (int) $db->admin['id']]);
        $foreign = (int) $db->pdo->lastInsertId();
        $as($db->admin);
        LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts/' . $foreign));
    },

    'per-user restriction: restricted user gets 404 on every contract route' => function () use ($setup, $db, $as, $get) {
        $setup();
        $db->pdo->prepare('INSERT INTO user_module_overrides (organization_id, user_id, module_id, enabled) VALUES (?, ?, ?, 0)')->execute([$db->orgId, (int) $db->member['id'], $db->moduleId]);
        $as($db->member);
        try {
            LiteAssert::throws(NotFoundException::class, fn () => $get('/contracts'));
        } finally {
            $db->pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = ? AND module_id = ?')->execute([(int) $db->member['id'], $db->moduleId]);
            $as($db->admin);
        }
    },

    'rollback leaves no test data behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'contracts'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM number_series")->fetchColumn());
    },
];
