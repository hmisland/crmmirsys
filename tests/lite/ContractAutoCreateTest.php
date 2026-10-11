<?php

declare(strict_types=1);

/**
 * Step 2.9 — organization setting "auto-create a draft contract when a
 * deal is won". Drives the real Sales DealService (this TEST may use
 * Sales; the Contracts module itself never does). crm_local only, one
 * transaction, rolled back.
 */

use App\Application\Services\TimelineService;
use App\Core\Application;
use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\ContractLinkRepository;
use Modules\Contracts\Services\ContractService;
use Modules\Sales\DealRepository;
use Modules\Sales\Events\DealStageChanged;
use Modules\Sales\PipelineRepository;
use Modules\Sales\PipelineStageRepository;
use Modules\Sales\Services\DealService;
use Modules\Sales\Services\PipelineService;

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
    $deals = $pdo->query("SELECT * FROM deals WHERE organization_id = {$orgId} ORDER BY id LIMIT 2")->fetchAll(\PDO::FETCH_ASSOC);
    $won = $deals !== [] ? $pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deals[0]['pipeline_id'] . ' AND is_won = 1 LIMIT 1')->fetchColumn() : false;
    $open = $deals !== [] ? $pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deals[0]['pipeline_id'] . ' AND is_won = 0 AND is_lost = 0 ORDER BY position, id LIMIT 1')->fetchColumn() : false;
    $lost = $deals !== [] ? $pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deals[0]['pipeline_id'] . ' AND is_lost = 1 LIMIT 1')->fetchColumn() : false;
    if (count($deals) < 2 || $won === false || $open === false) {
        $db->reason = 'needs 2 deals in the organization and a pipeline with an open and a won stage';
        throw new LiteSkip($db->reason);
    }

    CurrentUser::set($admin);
    OrganizationContext::set($orgId);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);
    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $db->moduleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, $db->moduleId]);
    // Both deals start open, so every test controls when they are won.
    foreach ($deals as $d) {
        $pdo->prepare("UPDATE deals SET stage_id = ?, status = 'open', closed_at = NULL WHERE id = ?")->execute([(int) $open, (int) $d['id']]);
    }

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->orgId = $orgId;
    $db->dealA = (int) $deals[0]['id'];
    $db->dealB = (int) $deals[1]['id'];
    $db->dealAName = (string) $deals[0]['name'];
    $db->dealAOwner = $deals[0]['owner_id'] !== null ? (int) $deals[0]['owner_id'] : null;
    $db->won = (int) $won;
    $db->open = (int) $open;
    $db->lost = $lost === false ? null : (int) $lost;
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
$dispatch = function (string $method, string $path, array $body = []) use ($db, $read): array {
    $db->c->make(ModuleGate::class)->reset();
    if ($method === 'POST') {
        $body['_csrf'] = Csrf::token();
    }
    $response = $db->app->router->dispatch(new Request($method, $path, [], $body, [], []));

    return [$read($response, 'status'), (string) $read($response, 'body'), $read($response, 'headers')['Location'] ?? null];
};
$deals = fn (): DealService => new DealService(
    new DealRepository(),
    new PipelineService(new PipelineRepository(), new PipelineStageRepository(), $db->c->make(AuthorizationEngine::class)),
    $db->c->make(TimelineService::class),
    $db->c->make(AuthorizationEngine::class),
    $db->c->make(EventBus::class),
);
$linkedTo = fn (int $dealId) => (new ContractLinkRepository())->contractIdsLinkedTo('deal', $dealId);
$setting = function (bool $on) use ($db): void {
    $db->c->make(ContractService::class)->saveSettings($db->admin, ['auto_create_on_deal_won' => $on ? '1' : '']);
};
$cycleToWon = function (int $dealId) use ($db, $deals): void {
    $deals()->changeStage($db->admin, $dealId, $db->open);
    $deals()->changeStage($db->admin, $dealId, $db->won);
};

return [
    'settings page: org admin sees and saves it; member is refused' => function () use ($setup, $db, $as, $dispatch) {
        $setup();
        $as($db->admin);
        [$status, $body] = $dispatch('GET', '/contracts/settings');
        LiteAssert::same(200, $status);
        LiteAssert::contains('name="auto_create_on_deal_won"', $body);
        LiteAssert::notContains('name="auto_create_on_deal_won" value="1" checked', $body, 'off by default');

        [, , $location] = $dispatch('POST', '/contracts/settings', ['auto_create_on_deal_won' => '1']);
        LiteAssert::same('/contracts/settings', $location);
        LiteAssert::same(1, (int) $db->pdo->query("SELECT auto_create_on_deal_won FROM contract_settings WHERE organization_id = {$db->orgId}")->fetchColumn());

        [, , $location] = $dispatch('POST', '/contracts/settings', []);
        LiteAssert::same(0, (int) $db->pdo->query("SELECT auto_create_on_deal_won FROM contract_settings WHERE organization_id = {$db->orgId}")->fetchColumn(), 'unticking turns it off');

        if ($db->member !== null) {
            $as($db->member);
            LiteAssert::throws(AuthorizationException::class, fn () => $dispatch('GET', '/contracts/settings'));
            LiteAssert::throws(AuthorizationException::class, fn () => $dispatch('POST', '/contracts/settings', ['auto_create_on_deal_won' => '1']));
            $as($db->admin);
        }
    },

    'setting off (default): a won deal creates nothing' => function () use ($setup, $db, $setting, $cycleToWon, $linkedTo) {
        $setup();
        $setting(false);
        $cycleToWon($db->dealA);
        LiteAssert::same([], $linkedTo($db->dealA));
    },

    'setting on: winning a deal creates exactly one draft, filled from the deal and linked to it' => function () use ($setup, $db, $setting, $cycleToWon, $linkedTo) {
        $setup();
        $setting(true);
        $cycleToWon($db->dealA);
        $ids = $linkedTo($db->dealA);
        LiteAssert::same(1, count($ids));

        $c = $db->pdo->query('SELECT * FROM contracts WHERE id = ' . $ids[0])->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same('draft', $c['status']);
        LiteAssert::same($db->dealAName, $c['title']);
        LiteAssert::same($db->dealAOwner ?? (int) $db->admin['id'], (int) $c['owner_id'], 'owner = deal owner');
        $texts = array_column((new TimelineEventRepository())->forSubject('contract', $ids[0]), 'description');
        LiteAssert::true((bool) array_filter($texts, fn ($t) => str_starts_with($t, 'Created automatically when deal')));
        $db->autoId = $ids[0];
    },

    'won again later: still one contract per deal' => function () use ($setup, $db, $setting, $cycleToWon, $linkedTo) {
        $setup();
        $setting(true);
        $cycleToWon($db->dealA);
        $cycleToWon($db->dealA);
        LiteAssert::same(1, count($linkedTo($db->dealA)));
    },

    'a deal that already has a linked contract gets no automatic one' => function () use ($setup, $db, $setting, $cycleToWon, $linkedTo) {
        $setup();
        $setting(true);
        $svc = $db->c->make(ContractService::class);
        $manual = $svc->create($db->admin, ['title' => 'LiteTest manual for B']);
        $svc->link($db->admin, (int) $manual['id'], 'deal', $db->dealB);
        $cycleToWon($db->dealB);
        LiteAssert::same([(int) $manual['id']], $linkedTo($db->dealB));
    },

    'lost, plain stage moves, and events without an actor create nothing' => function () use ($setup, $db, $setting, $deals, $linkedTo) {
        $setup();
        $setting(true);
        $before = $linkedTo($db->dealB);
        if ($db->lost !== null) {
            $deals()->changeStage($db->admin, $db->dealB, $db->lost);
        }
        $deals()->changeStage($db->admin, $db->dealB, $db->open);
        $db->c->make(EventBus::class)->dispatch(new DealStageChanged($db->orgId, 99999999, $db->won, 'Won', true, false, null));
        LiteAssert::same($before, $linkedTo($db->dealB));
    },

    'Contracts not entitled for the organization: nothing, even with the setting on' => function () use ($setup, $db, $setting, $cycleToWon, $linkedTo) {
        $setup();
        $setting(true);
        $db->pdo->exec("DELETE FROM contract_links WHERE linked_type = 'deal' AND linked_id = {$db->dealA}");
        $db->pdo->prepare('UPDATE organization_modules SET enabled = 0 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
        try {
            $cycleToWon($db->dealA);
            LiteAssert::same([], $linkedTo($db->dealA));
        } finally {
            $db->pdo->prepare('UPDATE organization_modules SET enabled = 1 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
        }
    },

    'tenant: an event naming another organization never creates here (or there)' => function () use ($setup, $db, $setting, $linkedTo) {
        $setup();
        $setting(true);
        $other = $db->pdo->query('SELECT id FROM organizations WHERE id <> ' . $db->orgId . ' ORDER BY id LIMIT 1')->fetchColumn();
        if ($other === false) {
            throw new LiteSkip('only one organization');
        }
        $before = (int) $db->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn();
        // Event claims org B but points at org A's deal, actor from org A.
        $db->c->make(EventBus::class)->dispatch(new DealStageChanged((int) $other, $db->dealA, $db->won, 'Won', true, false, (int) $db->admin['id']));
        LiteAssert::same($before, (int) $db->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn());
    },

    'rollback leaves no test data behind (deals restored, no settings row)' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query('SELECT COUNT(*) FROM contract_links')->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query('SELECT COUNT(*) FROM contract_settings')->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'contracts'")->fetchColumn());
    },
];
