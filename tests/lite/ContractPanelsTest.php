<?php

declare(strict_types=1);

/**
 * Step 2.6 — "Contracts" panels on Core contact and company pages,
 * through the real router (crm_local only, one transaction, rolled back).
 */

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\ContractPanels;
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
    $db->memberRoleId = $memberRoleId;
    $db->orgId = $orgId;
    $db->contactId = (new ContactRepository())->create(['first_name' => 'LiteTest', 'last_name' => 'PanelContact', 'owner_id' => (int) $admin['id']]);
    $db->companyId = (new CompanyRepository())->create(['name' => 'LiteTest Panel Co', 'owner_id' => (int) $admin['id']]);
    $db->ready = true;
};

$as = function (array $user) use ($db): void {
    CurrentUser::set($user);
    $db->c->make(ModuleGate::class)->reset();
};

$page = function (string $path) use ($db): string {
    $db->c->make(ModuleGate::class)->reset();
    $response = $db->app->router->dispatch(new Request('GET', $path, [], [], [], []));
    LiteAssert::same(200, (new \ReflectionProperty(Response::class, 'status'))->getValue($response), "status of {$path}");

    return (string) (new \ReflectionProperty(Response::class, 'body'))->getValue($response);
};

$activate = function () use ($db): void {
    if (!empty($db->moduleId)) {
        return;
    }
    $db->pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $db->moduleId = (int) $db->pdo->lastInsertId();
    $db->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$db->orgId, $db->moduleId]);
};

$svc = fn (): ContractService => $db->c->make(ContractService::class);
$setGrant = function (string $key, ?string $scope) use ($db): void {
    if ($scope === null) {
        $db->pdo->prepare('DELETE FROM role_permissions WHERE role_id = ? AND permission_key = ?')->execute([$db->memberRoleId, $key]);
    } else {
        $db->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE scope = VALUES(scope)')->execute([$db->memberRoleId, $key, $scope]);
    }
};

return [
    'registered for contact and company only, owned by the contracts module' => function () use ($setup, $db) {
        $setup();
        // Register into a gate-free twin registry to inspect exactly what Contracts contributes.
        $twin = new RelatedPanelRegistry(new ModuleGate(fn () => true, fn () => true));
        (new ContractPanels($db->c->make(ContractService::class), $db->c->make(\App\Infrastructure\Theme\TemplateEngine::class)))->registerAll($twin);
        LiteAssert::same(['contracts.contact@contracts@contracts.view'], array_map(fn ($p) => $p->id . '@' . $p->module . '@' . $p->permission, $twin->panelsFor('contact', $db->admin)));
        LiteAssert::same(['contracts.company@contracts@contracts.view'], array_map(fn ($p) => $p->id . '@' . $p->module . '@' . $p->permission, $twin->panelsFor('company', $db->admin)));
        LiteAssert::same([], $twin->panelsFor('lead', $db->admin));
        // Step 2.7: deal panel added (was "none until 2.7").
        LiteAssert::same(['contracts.deal@contracts@contracts.view'], array_map(fn ($p) => $p->id . '@' . $p->module . '@' . $p->permission, $twin->panelsFor('deal', $db->admin)));
    },

    'module not activated: no panel on contact or company pages' => function () use ($setup, $db, $as, $page) {
        $setup();
        $as($db->admin);
        LiteAssert::notContains('data-related-panel="contracts.contact"', $page('/contacts/' . $db->contactId));
        LiteAssert::notContains('data-related-panel="contracts.company"', $page('/companies/' . $db->companyId));
    },

    'activated: empty panel with a prefilled "New contract" button' => function () use ($setup, $db, $as, $page, $activate) {
        $setup();
        $activate();
        $as($db->admin);
        $body = $page('/contacts/' . $db->contactId);
        LiteAssert::contains('data-related-panel="contracts.contact"', $body);
        LiteAssert::contains('href="/contracts/create?contact_id=' . $db->contactId . '"', $body);

        $body = $page('/companies/' . $db->companyId);
        LiteAssert::contains('data-related-panel="contracts.company"', $body);
        LiteAssert::contains('href="/contracts/create?company_id=' . $db->companyId . '"', $body);
    },

    'the create form opens with the party preselected' => function () use ($setup, $db, $as, $page, $activate) {
        $setup();
        $activate();
        $as($db->admin);
        $response =$db->app->router->dispatch(new Request('GET', '/contracts/create', ['contact_id' => (string) $db->contactId], [], [], []));
        $body = (string) (new \ReflectionProperty(Response::class, 'body'))->getValue($response);
        LiteAssert::contains('value="' . $db->contactId . '" selected', $body);
    },

    'panel lists that party\'s contracts only, newest first, with status' => function () use ($setup, $db, $as, $page, $activate, $svc) {
        $setup();
        $activate();
        $as($db->admin);
        $svc()->create($db->admin, ['title' => 'LiteTest panel A', 'contact_id' => (string) $db->contactId]);
        $svc()->create($db->admin, ['title' => 'LiteTest panel B', 'contact_id' => (string) $db->contactId, 'company_id' => (string) $db->companyId]);
        $svc()->create($db->admin, ['title' => 'LiteTest unrelated']);

        $contactPage = $page('/contacts/' . $db->contactId);
        LiteAssert::contains('LiteTest panel A', $contactPage);
        LiteAssert::contains('LiteTest panel B', $contactPage);
        LiteAssert::notContains('LiteTest unrelated', $contactPage);
        LiteAssert::true(strpos($contactPage, 'LiteTest panel B') < strpos($contactPage, 'LiteTest panel A'), 'newest first');

        $companyPage = $page('/companies/' . $db->companyId);
        LiteAssert::contains('LiteTest panel B', $companyPage);
        LiteAssert::notContains('LiteTest panel A', $companyPage);
    },

    'more than the limit: shows 5 and a "view all" link to the filtered list' => function () use ($setup, $db, $as, $page, $activate, $svc) {
        $setup();
        $activate();
        $as($db->admin);
        for ($i = 1; $i <= ContractPanels::LIMIT + 1; $i++) {
            $svc()->create($db->admin, ['title' => "LiteTest many {$i}", 'company_id' => (string) $db->companyId]);
        }
        $body = $page('/companies/' . $db->companyId);
        LiteAssert::contains('href="/contracts?company=' . $db->companyId . '"', $body);

        $response =$db->app->router->dispatch(new Request('GET', '/contracts', ['company' => (string) $db->companyId], [], [], []));
        $list = (string) (new \ReflectionProperty(Response::class, 'body'))->getValue($response);
        foreach (range(1, ContractPanels::LIMIT + 1) as $i) {
            LiteAssert::contains("LiteTest many {$i}", $list);
        }
        LiteAssert::notContains('LiteTest panel A', $list, 'filtered by company');
        LiteAssert::contains('name="company" value="' . $db->companyId . '"', $list, 'filter kept in the filter form');
    },

    'member without contracts.view: no panel (contact page still works)' => function () use ($setup, $db, $as, $page, $activate, $setGrant) {
        $setup();
        $activate();
        $setGrant('contracts.view', null);
        try {
            $as($db->member);
            $body = $page('/contacts/' . $db->contactId);
            LiteAssert::notContains('data-related-panel="contracts.contact"', $body);
        } finally {
            $setGrant('contracts.view', 'organization');
            $as($db->admin);
        }
    },

    'member with own scope sees only own contracts; without create, no button' => function () use ($setup, $db, $as, $page, $activate, $svc, $setGrant) {
        $setup();
        $activate();
        $svc()->create($db->member, ['title' => 'LiteTest member own panel', 'contact_id' => (string) $db->contactId]);
        $svc()->create($db->admin, ['title' => 'LiteTest admin hidden panel', 'contact_id' => (string) $db->contactId]);
        $setGrant('contracts.view', 'own');
        $setGrant('contracts.create', null);
        try {
            $as($db->member);
            $body = $page('/contacts/' . $db->contactId);
            LiteAssert::contains('LiteTest member own panel', $body);
            LiteAssert::notContains('LiteTest admin hidden panel', $body);
            LiteAssert::notContains('/contracts/create?contact_id=', $body);
        } finally {
            $setGrant('contracts.view', 'organization');
            $setGrant('contracts.create', 'organization');
            $as($db->admin);
        }
    },

    'per-user restriction hides the panel' => function () use ($setup, $db, $as, $page, $activate) {
        $setup();
        $activate();
        $db->pdo->prepare('INSERT INTO user_module_overrides (organization_id, user_id, module_id, enabled) VALUES (?, ?, ?, 0)')->execute([$db->orgId, (int) $db->member['id'], $db->moduleId]);
        try {
            $as($db->member);
            LiteAssert::notContains('data-related-panel="contracts.contact"', $page('/contacts/' . $db->contactId));
        } finally {
            $db->pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = ? AND module_id = ?')->execute([(int) $db->member['id'], $db->moduleId]);
            $as($db->admin);
        }
    },

    'rollback leaves no test data behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contacts WHERE first_name = 'LiteTest'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'contracts'")->fetchColumn());
        LiteAssert::same('organization', $db->pdo->query("SELECT scope FROM role_permissions WHERE role_id = {$db->memberRoleId} AND permission_key = 'contracts.view'")->fetchColumn());
    },
];
