<?php

declare(strict_types=1);

/**
 * Step 2.7 — Deal ↔ Contract without Contracts importing Sales.
 * (This TEST may use Sales classes, to drive the real Sales code;
 * the Contracts module itself may not — see ContractsRecordTypeTest.)
 *
 * crm_local only, one transaction, rolled back.
 */

use App\Application\Services\TimelineService;
use App\Core\Application;
use App\Core\Events\EventBus;
use App\Core\Events\EventInterface;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TimelineEventRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use Modules\Contracts\ContractLinkRepository;
use Modules\Contracts\ContractsModule;
use Modules\Contracts\Listeners\DealStageChangedListener;
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

    $deal = $pdo->query("SELECT * FROM deals WHERE organization_id = {$orgId} AND status = 'open' ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($deal === false) {
        $db->reason = 'needs an open deal in the organization';
        throw new LiteSkip($db->reason);
    }
    $wonStage = $pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deal['pipeline_id'] . ' AND is_won = 1 LIMIT 1')->fetchColumn();
    $otherStage = $pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deal['pipeline_id'] . ' AND is_won = 0 AND is_lost = 0 AND id <> ' . (int) $deal['stage_id'] . ' LIMIT 1')->fetchColumn();

    CurrentUser::set($admin);
    OrganizationContext::set($orgId);
    $pdo->beginTransaction();
    register_shutdown_function(fn () => $pdo->inTransaction() ? $pdo->rollBack() : null);

    $pdo->exec("DELETE FROM modules WHERE `key` = 'contracts'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('contracts', 'Contracts', '0.1.0', 'addon', 0, 'active', '[]')");
    $db->moduleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$orgId, $db->moduleId]);
    $db->salesModuleId = (int) $pdo->query("SELECT id FROM modules WHERE `key` = 'sales'")->fetchColumn();

    $db->app = $app;
    $db->c = $app->container;
    $db->pdo = $pdo;
    $db->admin = $admin;
    $db->orgId = $orgId;
    $db->deal = $deal;
    $db->wonStage = $wonStage === false ? null : (int) $wonStage;
    $db->otherStage = $otherStage === false ? null : (int) $otherStage;
    $db->memberRoleId = (int) $pdo->query("SELECT id FROM roles WHERE `key` = 'member' AND is_system = 1 AND organization_id IS NULL")->fetchColumn();
    $otherUserId = $pdo->query("SELECT id FROM users WHERE organization_id = {$orgId} AND id <> {$adminId} AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $db->member = $otherUserId === false ? null : array_merge($users->findById((int) $otherUserId), ['role_id' => $db->memberRoleId, 'is_super_admin' => 0, 'team_id' => null, 'department_id' => null]);
    $db->ready = true;
};

$svc = fn (): ContractService => $db->c->make(ContractService::class);
$fresh = function () use ($db): void {
    $db->c->make(ModuleGate::class)->reset();
};
$timelineTypes = fn (int $contractId) => array_column((new TimelineEventRepository())->forSubject('contract', $contractId), 'type');
$timelineTexts = fn (int $contractId) => array_column((new TimelineEventRepository())->forSubject('contract', $contractId), 'description');
$salesEntitled = function (bool $on) use ($db, $fresh): void {
    $db->pdo->prepare('UPDATE organization_modules SET enabled = ? WHERE organization_id = ? AND module_id = ?')->execute([$on ? 1 : 0, $db->orgId, $db->salesModuleId]);
    $fresh();
};
$dealService = fn (): DealService => new DealService(
    new DealRepository(),
    new PipelineService(new PipelineRepository(), new PipelineStageRepository(), $db->c->make(AuthorizationEngine::class)),
    $db->c->make(TimelineService::class),
    $db->c->make(AuthorizationEngine::class),
    $db->c->make(EventBus::class),
);
$page = function (string $path) use ($db, $fresh): string {
    $fresh();
    $response = $db->app->router->dispatch(new Request('GET', $path, [], [], [], []));

    return (string) (new \ReflectionProperty(Response::class, 'body'))->getValue($response);
};

return [
    'Sales registers its own `deal` record type (P3): finder, deals.view, no /records support' => function () use ($setup, $db) {
        $setup();
        $type = $db->c->make(RecordTypeRegistry::class)->get('deal');
        LiteAssert::same('sales', $type?->module);
        LiteAssert::same('deals.view', $type->viewPermission);
        LiteAssert::true($type->finder instanceof \Closure);
        LiteAssert::false($type->recordSupport, '/records/deal/... keeps 404ing');
        LiteAssert::same('/deals/7', $type->showUrl(7));
    },

    'the subscribed event name is the real Sales event, with the properties the listener reads' => function () {
        LiteAssert::same(DealStageChanged::class, ContractsModule::DEAL_STAGE_CHANGED_EVENT);
        foreach (['organizationId', 'dealId', 'stageName', 'isWon', 'isLost', 'actorUserId'] as $property) {
            LiteAssert::true(property_exists(DealStageChanged::class, $property), "DealStageChanged::\${$property}");
        }
    },

    'link a deal: label from the deal, timeline entry, idempotent, link shown with URL' => function () use ($setup, $db, $svc, $timelineTypes, $fresh) {
        $setup();
        $fresh();
        $c = $svc()->create($db->admin, ['title' => 'LiteTest linked']);
        $db->linked = (int) $c['id'];

        $link = $svc()->link($db->admin, $db->linked, 'deal', (int) $db->deal['id']);
        LiteAssert::same($db->deal['name'], $link['linked_label']);
        LiteAssert::true(in_array('linked', $timelineTypes($db->linked), true));

        $again = $svc()->link($db->admin, $db->linked, 'deal', (int) $db->deal['id']);
        LiteAssert::same((int) $link['id'], (int) $again['id'], 'idempotent');

        $links = $svc()->links($db->admin, $db->linked);
        LiteAssert::same(1, count($links));
        LiteAssert::same('/deals/' . $db->deal['id'], $links[0]['url']);
        LiteAssert::same('nav.deals', $links[0]['label_key']);
    },

    'invalid targets are refused: unknown type, missing deal, contract-to-contract' => function () use ($setup, $db, $svc) {
        $setup();
        foreach ([['nope', 1], ['deal', 99999999], ['contract', $db->linked ?? 1]] as [$type, $id]) {
            try {
                $svc()->link($db->admin, $db->linked, $type, $id);
                throw new LiteAssertionFailed("expected refusal for {$type} #{$id}");
            } catch (ValidationException $e) {
                LiteAssert::same(['contracts.error.link_target_invalid'], $e->errors['link']);
            }
        }
    },

    'tenant: another organization\'s deal cannot be linked' => function () use ($setup, $db, $svc) {
        $setup();
        $other = $db->pdo->query('SELECT id FROM organizations WHERE id <> ' . $db->orgId . ' ORDER BY id LIMIT 1')->fetchColumn();
        if ($other === false) {
            throw new LiteSkip('only one organization');
        }
        // A deal in another organization (inside this suite's transaction).
        $db->pdo->prepare('INSERT INTO pipelines (organization_id, name) VALUES (?, ?)')->execute([(int) $other, 'LiteTest foreign pipeline']);
        $pipelineId = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare('INSERT INTO pipeline_stages (organization_id, pipeline_id, name) VALUES (?, ?, ?)')->execute([(int) $other, $pipelineId, 'LiteTest stage']);
        $stageId = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare('INSERT INTO deals (organization_id, pipeline_id, stage_id, name) VALUES (?, ?, ?, ?)')->execute([(int) $other, $pipelineId, $stageId, 'LiteTest foreign deal']);
        $foreign = (int) $db->pdo->lastInsertId();

        LiteAssert::throws(ValidationException::class, fn () => $svc()->link($db->admin, $db->linked, 'deal', (int) $foreign));
    },

    'Sales disabled for the organization: link kept, shown as label only; new links refused' => function () use ($setup, $db, $svc, $salesEntitled) {
        $setup();
        $salesEntitled(false);
        try {
            $links = $svc()->links($db->admin, $db->linked);
            LiteAssert::same(null, $links[0]['url']);
            LiteAssert::same($db->deal['name'], $links[0]['label'], 'snapshot label');
            $c2 = $svc()->create($db->admin, ['title' => 'LiteTest no sales']);
            LiteAssert::throws(ValidationException::class, fn () => $svc()->link($db->admin, (int) $c2['id'], 'deal', (int) $db->deal['id']));
        } finally {
            $salesEntitled(true);
        }
    },

    'RBAC: member cannot link to someone else\'s contract' => function () use ($setup, $db, $svc, $fresh) {
        $setup();
        if ($db->member === null) {
            throw new LiteSkip('needs a second user');
        }
        $fresh();
        LiteAssert::throws(AuthorizationException::class, fn () => $svc()->link($db->member, $db->linked, 'deal', (int) $db->deal['id']));
    },

    'deal page shows the Contracts panel with the linked contract' => function () use ($setup, $db, $page) {
        $setup();
        $body = $page('/deals/' . $db->deal['id']);
        LiteAssert::contains('data-related-panel="contracts.deal"', $body);
        LiteAssert::contains('LiteTest linked', $body);
        LiteAssert::contains('href="/contracts/' . $db->linked . '"', $body);
    },

    'contract page lists the linked deal with a link and an unlink button' => function () use ($setup, $db, $page) {
        $setup();
        $body = $page('/contracts/' . $db->linked);
        LiteAssert::contains('href="/deals/' . $db->deal['id'] . '"', $body);
        LiteAssert::contains('/contracts/' . $db->linked . '/links/', $body);
    },

    'real Sales stage change → EventBus → linked contract\'s timeline (no import in Contracts)' => function () use ($setup, $db, $dealService, $timelineTexts) {
        $setup();
        if ($db->otherStage === null || $db->wonStage === null) {
            throw new LiteSkip('pipeline needs an open stage and a won stage');
        }
        $dealService()->changeStage($db->admin, (int) $db->deal['id'], $db->otherStage);
        LiteAssert::true(count(array_filter($timelineTexts($db->linked), fn ($t) => str_starts_with($t, 'Linked deal moved to stage'))) === 1, 'stage move recorded');

        $dealService()->changeStage($db->admin, (int) $db->deal['id'], $db->wonStage);
        LiteAssert::true(in_array('Linked deal won', $timelineTexts($db->linked), true), 'won recorded');
    },

    'contracts not entitled for the organization: the listener does nothing' => function () use ($setup, $db, $timelineTypes) {
        $setup();
        $before = count(array_keys($timelineTypes($db->linked), 'deal_stage_changed'));
        $db->pdo->prepare('UPDATE organization_modules SET enabled = 0 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
        try {
            $db->c->make(EventBus::class)->dispatch(new DealStageChanged($db->orgId, (int) $db->deal['id'], 1, 'X', false, false, (int) $db->admin['id']));
            LiteAssert::same($before, count(array_keys($timelineTypes($db->linked), 'deal_stage_changed')));
        } finally {
            $db->pdo->prepare('UPDATE organization_modules SET enabled = 1 WHERE organization_id = ? AND module_id = ?')->execute([$db->orgId, $db->moduleId]);
        }
    },

    'unrelated deals and other organizations do not touch the contract' => function () use ($setup, $db, $timelineTypes) {
        $setup();
        $before = count($timelineTypes($db->linked));
        $bus = $db->c->make(EventBus::class);
        $bus->dispatch(new DealStageChanged($db->orgId, 99999999, 1, 'X', true, false, (int) $db->admin['id']));
        $other = $db->pdo->query('SELECT id FROM organizations WHERE id <> ' . $db->orgId . ' LIMIT 1')->fetchColumn();
        if ($other !== false) {
            $bus->dispatch(new DealStageChanged((int) $other, (int) $db->deal['id'], 1, 'X', true, false, null));
        }
        LiteAssert::same($before, count($timelineTypes($db->linked)));
    },

    'an event of an unexpected shape is ignored, not fatal' => function () use ($setup, $db) {
        $setup();
        $listener = new DealStageChangedListener(new ContractLinkRepository(), $db->c->make(TimelineService::class));
        $listener->handle(new class () implements EventInterface {
            public int $organizationId = 1;
        });
        LiteAssert::true(true);
    },

    'unlink: removes the link (and refuses an unknown link id)' => function () use ($setup, $db, $svc) {
        $setup();
        $link = $svc()->links($db->admin, $db->linked)[0];
        LiteAssert::throws(NotFoundException::class, fn () => $svc()->unlink($db->admin, $db->linked, 99999999));
        $svc()->unlink($db->admin, $db->linked, $link['id']);
        LiteAssert::same([], $svc()->links($db->admin, $db->linked));
    },

    'rollback leaves no test data behind' => function () use ($setup, $db) {
        $setup();
        $db->pdo->rollBack();
        LiteAssert::same(0, (int) $db->pdo->query("SELECT COUNT(*) FROM contracts WHERE title LIKE 'LiteTest%'")->fetchColumn());
        LiteAssert::same(0, (int) $db->pdo->query('SELECT COUNT(*) FROM contract_links')->fetchColumn());
        LiteAssert::same((int) $db->deal['stage_id'], (int) $db->pdo->query('SELECT stage_id FROM deals WHERE id = ' . (int) $db->deal['id'])->fetchColumn(), 'deal stage restored');
        LiteAssert::same('open', $db->pdo->query('SELECT status FROM deals WHERE id = ' . (int) $db->deal['id'])->fetchColumn());
    },
];
