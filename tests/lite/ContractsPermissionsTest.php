<?php

declare(strict_types=1);

/**
 * Step 2.3 — module-registered permission keys (P1) and Contracts grants.
 *
 * Part 1 (no DB): Permission::registerModuleKeys() rules, with a made-up
 * "widgets" module (cleaned up after each test).
 * Part 2 (crm_local only, one transaction, rolled back): the real
 * Contracts keys, the role editor, role validation and the default
 * grants from migration contracts/0003.
 */

use App\Application\Services\RoleService;
use App\Core\Application;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\ContractPermission;

$withWidgets = function (callable $test): void {
    try {
        $test();
    } finally {
        Permission::forgetModuleKeys('widgets');
    }
};

// ---- Part 2 setup -----------------------------------------------------------

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
        $applied = (int) $pdo->query("SELECT COUNT(*) FROM module_migrations WHERE module_key = 'contracts' AND migration = '0003_grant_contracts_permissions'")->fetchColumn();
        if ($applied !== 1) {
            throw new \RuntimeException('contracts/0003 not applied to crm_local');
        }
        $userId = $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.`key` = 'org_admin' AND u.organization_id IS NOT NULL AND u.status = 'active' ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        if ($userId === false) {
            throw new \RuntimeException('no active org_admin user');
        }
    } catch (\Throwable $e) {
        $db->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($db->reason);
    }

    $admin = (new UserRepository())->findById((int) $userId);
    CurrentUser::set($admin);
    OrganizationContext::set((int) $admin['organization_id']);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);

    $memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
    $base = ['organization_id' => (int) $admin['organization_id'], 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null];

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->member = $base + ['id' => 900001, 'role_id' => $memberRoleId];
    $db->noRole = $base + ['id' => 900002, 'role_id' => null];
    $db->superAdmin = ['id' => 900003, 'organization_id' => null, 'role_id' => null, 'is_super_admin' => 1, 'team_id' => null, 'department_id' => null];
    $db->ready = true;
};

$can = fn (array $user, string $key, ?array $record = null): bool => $db->c->make(AuthorizationEngine::class)->can($user, $key, $record);

return [
    // ---- Part 1: registerModuleKeys() ----------------------------------
    'a module can register keys under its own prefix; all() and exists() include them' => fn () => $withWidgets(function () {
        $registered = Permission::registerModuleKeys('widgets', ['widgets.view', 'widgets.manage_settings', 'widgets.items.export']);

        LiteAssert::same(['widgets.view', 'widgets.manage_settings', 'widgets.items.export'], $registered);
        LiteAssert::true(Permission::exists('widgets.view'));
        LiteAssert::true(in_array('widgets.items.export', Permission::all(), true));
        LiteAssert::same(['widgets.view', 'widgets.manage_settings', 'widgets.items.export'], Permission::moduleKeys('widgets'));
    }),

    'Core keys are unchanged and come first in all()' => fn () => $withWidgets(function () {
        $coreSnapshot = array_values(array_diff(Permission::all(), Permission::moduleKeys()));
        Permission::registerModuleKeys('widgets', ['widgets.view']);
        $all = Permission::all();

        LiteAssert::same($coreSnapshot, array_slice($all, 0, count($coreSnapshot)));
        foreach (['contacts.view', 'leads.edit', 'followups.view', 'invoices.issue', 'roles.edit'] as $key) {
            LiteAssert::true(Permission::exists($key), "core key {$key}");
        }
    }),

    'keys outside the module prefix, malformed keys and non-strings are skipped' => fn () => $withWidgets(function () {
        $registered = Permission::registerModuleKeys('widgets', [
            'contacts.view', 'other.view', 'widgets', 'widgets.', 'Widgets.View', 'widgets.View', 'widgets..x', 'widgetsx.view', 42, null, 'widgets.ok',
        ]);

        LiteAssert::same(['widgets.ok'], $registered);
        LiteAssert::false(Permission::exists('other.view'));
        LiteAssert::false(Permission::exists('widgetsx.view'));
    }),

    'a module can never take over a Core key, even under a matching prefix' => function () {
        try {
            $registered = Permission::registerModuleKeys('contacts', ['contacts.view', 'contacts.extra']);
            LiteAssert::same(['contacts.extra'], $registered, 'core contacts.view refused');
            LiteAssert::same(['contacts.extra'], Permission::moduleKeys('contacts'));
        } finally {
            Permission::forgetModuleKeys('contacts');
        }
        LiteAssert::true(Permission::exists('contacts.view'), 'core key still there');
        LiteAssert::false(Permission::exists('contacts.extra'));
    },

    'invalid module names register nothing' => function () {
        LiteAssert::same([], Permission::registerModuleKeys('Bad Module', ['Bad Module.view']));
        LiteAssert::same([], Permission::registerModuleKeys('', ['.view']));
    },

    're-registering is idempotent (no duplicates in all())' => fn () => $withWidgets(function () {
        Permission::registerModuleKeys('widgets', ['widgets.view']);
        LiteAssert::same(['widgets.view'], Permission::registerModuleKeys('widgets', ['widgets.view']));
        LiteAssert::same(1, count(array_keys(Permission::all(), 'widgets.view')));
    }),

    'ContractPermission: 9 unique keys, all under contracts.' => function () {
        LiteAssert::same(9, count(array_unique(ContractPermission::ALL)));
        foreach (ContractPermission::ALL as $key) {
            LiteAssert::true(str_starts_with($key, 'contracts.'), $key);
        }
    },

    // ---- Part 2: real module, local DB ---------------------------------
    'real app: Contracts registers exactly its 9 keys at boot' => function () use ($setup) {
        $setup();
        LiteAssert::same(ContractPermission::ALL, Permission::moduleKeys('contracts'));
        foreach (ContractPermission::ALL as $key) {
            LiteAssert::true(Permission::exists($key), $key);
        }
    },

    'role editor (/org/roles) lists the contract keys' => function () use ($setup, $db) {
        $setup();
        $response = $db->app->router->dispatch(new Request('GET', '/org/roles', [], [], [], []));
        $body = (string) (new \ReflectionProperty(Response::class, 'body'))->getValue($response);

        LiteAssert::same(200, (new \ReflectionProperty(Response::class, 'status'))->getValue($response));
        foreach (ContractPermission::ALL as $key) {
            LiteAssert::contains('data-key="' . $key . '"', $body, "checkbox for {$key}");
        }
    },

    'saving a role accepts contract keys and still rejects unknown ones' => function () use ($setup, $db) {
        $setup();
        $roles = $db->c->make(RoleService::class);

        // Acceptance: the exact check RoleService::create()/update() run on
        // save. (The save itself opens its own DB transaction, which can't
        // nest inside this suite's rollback transaction.)
        $validate = new \ReflectionMethod(RoleService::class, 'assertValidPermissions');
        $validate->invoke($roles, ['contracts.view' => 'team', 'contracts.edit' => 'own', 'contacts.view' => 'organization']);

        // Rejection goes through the real create(): it throws before any write.
        LiteAssert::throws(ValidationException::class, fn () => $roles->create($db->admin, 'LiteTest bad role', ['contracts.nope' => 'own']));
        LiteAssert::throws(ValidationException::class, fn () => $roles->create($db->admin, 'LiteTest bad role 2', ['widgets.view' => 'own']));
    },

    'default grants (migration 0003) are exactly as designed' => function () use ($setup, $db) {
        $setup();
        $rows = $db->pdo->query(
            "SELECT CONCAT(r.`key`, ':', rp.permission_key, ':', rp.scope) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE rp.permission_key LIKE 'contracts.%' AND r.is_system = 1 AND r.`key` IN ('org_admin', 'member') ORDER BY 1"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $expected = array_merge(
            ['member:contracts.create:organization', 'member:contracts.edit:own', 'member:contracts.view:organization'],
            array_map(fn ($k) => "org_admin:{$k}:organization", ContractPermission::ALL)
        );
        sort($expected);
        LiteAssert::same($expected, $rows);
    },

    'org_admin holds every contract permission' => function () use ($setup, $db, $can) {
        $setup();
        foreach (ContractPermission::ALL as $key) {
            LiteAssert::true($can($db->admin, $key), $key);
        }
    },

    'member: view/create; edit only own rows; nothing admin-level' => function () use ($setup, $db, $can) {
        $setup();
        LiteAssert::true($can($db->member, ContractPermission::VIEW));
        LiteAssert::true($can($db->member, ContractPermission::CREATE));
        LiteAssert::true($can($db->member, ContractPermission::EDIT, ['owner_id' => $db->member['id']]), 'own row');
        LiteAssert::false($can($db->member, ContractPermission::EDIT, ['owner_id' => 1]), 'someone else\'s row');
        foreach ([ContractPermission::DELETE, ContractPermission::APPROVE, ContractPermission::ACTIVATE, ContractPermission::TERMINATE, ContractPermission::RENEW, ContractPermission::MANAGE_SETTINGS] as $key) {
            LiteAssert::false($can($db->member, $key), $key);
        }
    },

    'a user without a role holds no contract permission; super admin holds all' => function () use ($setup, $db, $can) {
        $setup();
        foreach (ContractPermission::ALL as $key) {
            LiteAssert::false($can($db->noRole, $key), "no role: {$key}");
            LiteAssert::true($can($db->superAdmin, $key), "super admin: {$key}");
        }
    },

    'rollback leaves no test roles behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM roles WHERE name LIKE 'LiteTest%'")->fetchColumn());
    },
];
