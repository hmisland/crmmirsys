<?php

declare(strict_types=1);

/**
 * Step 2.2 — Contracts skeleton + `contract` record type.
 *
 * Part 1 (no DB): RecordTypeRegistry::find() rules (P2).
 * Part 2 (no DB): architecture — Contracts never references another module.
 * Part 3 (crm_local only, one transaction, rolled back): the real module
 * registers `contract`, and the full chain installed → active → org
 * entitled → user override → permission → record scope → tenant holds.
 */

use App\Application\Controllers\RecordSupportController;
use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Core\Application;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\NoteRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;
use Tests\Lite\Fixtures\FakeGate;

require_once __DIR__ . '/Fixtures/FakeModules.php';

// ---- Part 1 helpers ------------------------------------------------------

$rows = [
    1 => ['id' => 1, 'organization_id' => 10, 'owner_id' => 7],
    2 => ['id' => 2, 'organization_id' => 10, 'owner_id' => 99],
    3 => ['id' => 3, 'organization_id' => 20, 'owner_id' => 7],
];
$finder = fn (int $id): ?array => $rows[$id] ?? null;
$user = ['id' => 7, 'organization_id' => 10];

/** Gate whose permission check honours "own" scope when a record is given. */
$ownScopeGate = fn (array $enabled, array $grants) => new ModuleGate(
    fn (string $m, array $u) => in_array($m, $enabled, true),
    function (array $u, string $p, ?array $record = null) use ($grants): bool {
        if (!in_array($p, $grants, true)) {
            return false;
        }

        return $record === null || $record['owner_id'] === $u['id'];
    },
);

// ---- Part 3 setup (local DB) -----------------------------------------------

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
        if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts'")->fetchColumn() !== 1) {
            throw new \RuntimeException('contracts migrations not applied to crm_local');
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

    $user = (new UserRepository())->findById((int) $userId);
    CurrentUser::set($user);
    OrganizationContext::set((int) $user['organization_id']);
    $db->grantsBefore = (int) $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_key LIKE 'contracts.%'")->fetchColumn();
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);

    $orgId = (int) $user['organization_id'];
    $otherOrg = $pdo->query('SELECT id FROM organizations WHERE id <> ' . $orgId . ' ORDER BY id LIMIT 1')->fetchColumn();
    $memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();

    $insert = function (int $org, int $owner, string $title) use ($pdo): int {
        $stmt = $pdo->prepare('INSERT INTO contracts (organization_id, title, owner_id, created_by_user_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$org, $title, $owner, $owner]);

        return (int) $pdo->lastInsertId();
    };

    // Simulate a Super Admin installing the module and entitling this org:
    // exactly the rows /admin/modules (sync) and /admin/organizations/{id}/modules write.
    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->user = $user;
    $db->orgId = $orgId;
    $db->memberRoleId = $memberRoleId;
    $db->mine = $insert($orgId, (int) $user['id'], 'LiteTest contract');
    $db->otherOrgContract = $otherOrg !== false ? $insert((int) $otherOrg, (int) $user['id'], 'LiteTest other org') : null;
    $db->activate = function () use ($pdo, $orgId, $user): int {
        $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
        $moduleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, $moduleId]);
        $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'contracts.view', 'organization')")->execute([(int) $user['role_id']]);

        return $moduleId;
    };
    $db->ready = true;
};

$types = fn () => $db->c->make(RecordTypeRegistry::class);
$freshGate = function () use ($db): void {
    $db->c->make(ModuleGate::class)->reset();
};
$noteRequest = function (string $type, int $id): Request {
    $r = new Request('POST', "/records/{$type}/{$id}/notes", [], ['body' => 'lite contract note'], [], []);
    $r->setAttribute('type', $type);
    $r->setAttribute('id', (string) $id);

    return $r;
};
$recordSupport = fn () => new RecordSupportController(
    $db->c->make(TemplateEngine::class),
    $db->c->make(NoteService::class),
    $db->c->make(TagService::class),
    $db->c->make(AttachmentService::class),
    $db->c->make(RecordTypeRegistry::class),
);

return [
    // ---- Part 1: find() rules ------------------------------------------
    'find(): returns the row when module available, permission held, same org' => function () use ($finder, $user) {
        $registry = new RecordTypeRegistry(FakeGate::make(['contracts'], ['contracts.view']));
        $registry->register(new RecordType('contract', 'contracts', 'contracts.view', 'x', '/c/{id}', null, $finder));

        LiteAssert::same(1, $registry->find('contract', 1, $user)['id'] ?? null);
    },

    'find(): null without a finder (existing registrations unchanged)' => function () use ($user) {
        $registry = new RecordTypeRegistry(FakeGate::make([], ['contacts.view']));
        $registry->register(new RecordType('contact', null, 'contacts.view', 'nav.contacts', '/contacts/{id}'));

        LiteAssert::same(null, $registry->find('contact', 1, $user));
    },

    'find(): null when module unavailable, permission missing, guest, unknown type or bad id' => function () use ($finder, $user) {
        $type = new RecordType('contract', 'contracts', 'contracts.view', 'x', '/c/{id}', null, $finder);

        $off = new RecordTypeRegistry(FakeGate::make([], ['contracts.view']));
        $off->register($type);
        LiteAssert::same(null, $off->find('contract', 1, $user), 'module off');

        $noPerm = new RecordTypeRegistry(FakeGate::make(['contracts'], []));
        $noPerm->register($type);
        LiteAssert::same(null, $noPerm->find('contract', 1, $user), 'no permission');

        $ok = new RecordTypeRegistry(FakeGate::make(['contracts'], ['contracts.view']));
        $ok->register($type);
        LiteAssert::same(null, $ok->find('contract', 1, null), 'guest');
        LiteAssert::same(null, $ok->find('nope', 1, $user), 'unknown type');
        LiteAssert::same(null, $ok->find('contract', 0, $user), 'bad id');
        LiteAssert::same(null, $ok->find('contract', 404, $user), 'missing row');
    },

    'find(): a row from another organization is never returned' => function () use ($finder, $user) {
        $registry = new RecordTypeRegistry(FakeGate::make(['contracts'], ['contracts.view']));
        $registry->register(new RecordType('contract', 'contracts', 'contracts.view', 'x', '/c/{id}', null, $finder));

        LiteAssert::same(null, $registry->find('contract', 3, $user));
    },

    'find(): record-level scope (own) is applied to the row' => function () use ($finder, $user, $ownScopeGate) {
        $registry = new RecordTypeRegistry($ownScopeGate(['contracts'], ['contracts.view']));
        $registry->register(new RecordType('contract', 'contracts', 'contracts.view', 'x', '/c/{id}', null, $finder));

        LiteAssert::same(1, $registry->find('contract', 1, $user)['id'] ?? null, 'own row');
        LiteAssert::same(null, $registry->find('contract', 2, $user), 'someone else\'s row');
    },

    // ---- Part 2: architecture ------------------------------------------
    'Contracts module references no other module (code and schema)' => function () {
        $dir = dirname(__DIR__, 2) . '/modules/Contracts';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        // top-level Modules\X, with or without a leading "\" — but not
        // Core's App\Infrastructure\Modules\... (preceded by "word\")
        $forbiddenNamespaces = '/(?<!\w)(?<!\w\\\\)Modules\\\\{1,2}(?!Contracts\b)[A-Z]/';
        $forbiddenTables = '/\b(deals|pipelines|pipeline_stages|tasks|invoices|proformas|payments|products|projects|follow_ups|tickets|appointments|wallets?)\b/';

        // The ONLY allowed mention of another module (decision G2, step 2.7):
        // the deal event's class name as a string literal, once, in
        // ContractsModule.php — an EventBus subscription, never an import.
        $allowed = "'Modules\\\\Sales\\\\Events\\\\DealStageChanged'";
        $allowedSeen = 0;

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if ($file->getFilename() === 'ContractsModule.php') {
                $allowedSeen = substr_count($source, $allowed);
                $source = str_replace($allowed, "''", $source);
            }
            // code only: comments may explain future integrations by name
            $code = str_ends_with($file->getFilename(), '.php')
                ? implode('', array_map(
                    fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                    token_get_all($source)
                ))
                : $source;
            LiteAssert::same(0, preg_match($forbiddenNamespaces, $code), 'other module namespace in ' . $file->getFilename());
            LiteAssert::same(0, preg_match($forbiddenTables, $code), 'other module table in ' . $file->getFilename());
        }

        LiteAssert::same(1, $allowedSeen, 'the event-name string appears exactly once, in ContractsModule.php');

        $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
        LiteAssert::same([], $manifest['dependencies']);
    },

    // ---- Part 3: real module, local DB ---------------------------------
    'real app discovers Contracts and registers the `contract` type with a finder' => function () use ($setup, $types) {
        $setup();
        LiteAssert::true(ModuleRegistry::instance()->isInstalled('contracts'));
        $type = $types()->get('contract');
        LiteAssert::same('contracts', $type?->module);
        LiteAssert::same('contracts.view', $type->viewPermission);
        LiteAssert::true($type->finder instanceof \Closure);
        LiteAssert::same('/contracts/5', $type->showUrl(5));
    },

    'installed on disk but not activated by Super Admin: unusable' => function () use ($setup, $db, $types, $freshGate, $recordSupport, $noteRequest) {
        $setup();
        $freshGate();
        LiteAssert::false($types()->isAvailable('contract', $db->user));
        LiteAssert::same(null, $types()->find('contract', $db->mine, $db->user));
        LiteAssert::throws(NotFoundException::class, fn () => $recordSupport()->addNote($noteRequest('contract', $db->mine)));
    },

    'activated + entitled + contracts.view: find works and notes attach to a contract' => function () use ($setup, $db, $types, $freshGate, $recordSupport, $noteRequest) {
        $setup();
        $db->moduleId ??= ($db->activate)();
        $freshGate();

        LiteAssert::same('LiteTest contract', $types()->find('contract', $db->mine, $db->user)['title'] ?? null);

        $response = $recordSupport()->addNote($noteRequest('contract', $db->mine));
        $location = (new \ReflectionProperty(Response::class, 'headers'))->getValue($response)['Location'] ?? '';
        LiteAssert::same('/contracts/' . $db->mine, $location);
        LiteAssert::same(1, count((new NoteRepository())->forSubject('contract', $db->mine)));
    },

    'tenant isolation: another organization\'s contract is not found' => function () use ($setup, $db, $types, $freshGate) {
        $setup();
        if ($db->otherOrgContract === null) {
            throw new LiteSkip('only one organization in crm_local');
        }
        $db->moduleId ??= ($db->activate)();
        $freshGate();
        LiteAssert::same(null, $types()->find('contract', $db->otherOrgContract, $db->user));
    },

    'record scope: member with contracts.view (own) sees only own contracts' => function () use ($setup, $db, $types, $freshGate) {
        $setup();
        $db->moduleId ??= ($db->activate)();
        // Since 2.3 the member default is organization scope; narrow it to own for this test (rolled back).
        $db->pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'contracts.view', 'own') ON DUPLICATE KEY UPDATE scope = 'own'")->execute([$db->memberRoleId]);
        $freshGate();

        $member = ['id' => -5, 'organization_id' => $db->orgId, 'role_id' => $db->memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null];
        LiteAssert::same(null, $types()->find('contract', $db->mine, $member), 'not the owner');

        $member['id'] = (int) $db->user['id'];
        LiteAssert::same($db->mine, (int) ($types()->find('contract', $db->mine, $member)['id'] ?? 0), 'owner');
    },

    'organization entitlement switched off: unusable again' => function () use ($setup, $db, $types, $freshGate) {
        $setup();
        $db->moduleId ??= ($db->activate)();
        $db->pdo->prepare('UPDATE organization_modules SET enabled = 0 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
        $freshGate();
        LiteAssert::false($types()->canView('contract', $db->user));
        $db->pdo->prepare('UPDATE organization_modules SET enabled = 1 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
    },

    'per-user restriction (Org Admin restricts a member): unusable for that user' => function () use ($setup, $db, $types, $freshGate) {
        $setup();
        $db->moduleId ??= ($db->activate)();
        $db->pdo->prepare('INSERT INTO user_module_overrides (organization_id, user_id, module_id, enabled) VALUES (?, ?, ?, 0)')->execute([$db->orgId, (int) $db->user['id'], $db->moduleId]);
        $freshGate();
        LiteAssert::false($types()->canView('contract', $db->user));
        $db->pdo->prepare('DELETE FROM user_module_overrides WHERE user_id = ? AND module_id = ?')->execute([(int) $db->user['id'], $db->moduleId]);
    },

    'module globally disabled: unusable' => function () use ($setup, $db, $types, $freshGate) {
        $setup();
        $db->moduleId ??= ($db->activate)();
        $db->pdo->exec("UPDATE modules SET status = 'disabled' WHERE `key` = 'contracts'");
        $freshGate();
        LiteAssert::false($types()->isAvailable('contract', $db->user));
        $db->pdo->exec("UPDATE modules SET status = 'active' WHERE `key` = 'contracts'");
    },

    'rollback leaves no test rows behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'contracts'")->fetchColumn());
        LiteAssert::same($db->grantsBefore, (int) $db->pdo->query("SELECT COUNT(*) FROM role_permissions WHERE permission_key LIKE 'contracts.%'")->fetchColumn(), 'grants back to pre-test state');
    },
];
