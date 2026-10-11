<?php

declare(strict_types=1);

/**
 * Phase 5 Step 3 — Deal → Project through EventBus (decision D4).
 * The real Sales DealService wins/loses deals; Projects reacts only via
 * its listener subscribed by the event's class-name string.
 *
 * - shape guard; timeline on linked projects;
 * - auto-create: OFF by default; when ON, exactly one draft project per
 *   deal, linked, owned by the deal owner; never when Projects isn't
 *   entitled; never on lost;
 * - independent of Contracts' own auto-create setting;
 * - settings page + permission.
 * crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Application\Services\TimelineService;
use App\Core\Events\EventBus;
use App\Core\Events\EventInterface;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Contracts\Services\ContractService;
use Modules\Projects\ProjectLinkRepository;
use Modules\Projects\ProjectsModule;
use Modules\Sales\DealRepository;
use Modules\Sales\Events\DealStageChanged;
use Modules\Sales\PipelineRepository;
use Modules\Sales\PipelineStageRepository;
use Modules\Sales\Services\DealService;
use Modules\Sales\Services\PipelineService;
use Tests\Lite\Support\ProjectsLite;

$booter = ProjectsLite::booter(true);
$ctx = new \stdClass();
$ctx->ready = false;

$boot = function () use ($booter, $ctx) {
    $a = $booter();
    if ($ctx->ready) {
        return $a;
    }
    $deals = $a->pdo->query("SELECT * FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 3")->fetchAll(\PDO::FETCH_ASSOC);
    // Fewer than 3 deals locally: clone the first one (inside the rolled-back transaction).
    for ($i = count($deals); $deals !== [] && $i < 3; $i++) {
        $copy = $deals[0];
        unset($copy['id']);
        $copy['name'] = 'LiteTest DE deal ' . $i;
        $columns = array_keys($copy);
        $a->pdo->prepare('INSERT INTO deals (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($copy));
        $deals[] = $a->pdo->query('SELECT * FROM deals WHERE id = ' . (int) $a->pdo->lastInsertId())->fetch(\PDO::FETCH_ASSOC);
    }
    $stage = fn (string $where) => $deals !== [] ? $a->pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deals[0]['pipeline_id'] . " AND {$where} ORDER BY position, id LIMIT 1")->fetchColumn() : false;
    $ctx->won = $stage('is_won = 1');
    $ctx->open = $stage('is_won = 0 AND is_lost = 0');
    $ctx->lost = $stage('is_lost = 1');
    if (count($deals) < 3 || $ctx->won === false || $ctx->open === false) {
        throw new LiteSkip('needs 3 deals in the organization and a pipeline with an open and a won stage');
    }
    foreach ($deals as $d) {
        $a->pdo->prepare("UPDATE deals SET pipeline_id = ?, stage_id = ?, status = 'open', closed_at = NULL WHERE id = ?")->execute([(int) $deals[0]['pipeline_id'], (int) $ctx->open, (int) $d['id']]);
    }
    $ctx->deals = array_map(fn ($d) => (int) $d['id'], $deals);
    $ctx->rows = array_column($deals, null, 'id');
    $ctx->ready = true;

    return $a;
};
$dealService = fn ($a): DealService => new DealService(
    new DealRepository(),
    new PipelineService(new PipelineRepository(), new PipelineStageRepository(), $a->container()->make(AuthorizationEngine::class)),
    $a->container()->make(TimelineService::class),
    $a->container()->make(AuthorizationEngine::class),
    $a->container()->make(EventBus::class),
);
$win = function ($a, int $dealId) use ($dealService, $ctx): void {
    $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->open);
    $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->won);
};
$linked = fn (int $dealId) => (new ProjectLinkRepository())->projectIdsLinkedTo('deal', $dealId);
$setting = fn ($a, bool $on) => ProjectsLite::service($a)->saveSettings($a->admin, ['auto_create_on_deal_won' => $on ? '1' : '']);

return [
    'the subscribed event name is the real Sales event class' => function () {
        LiteAssert::same(DealStageChanged::class, ProjectsModule::DEAL_STAGE_CHANGED_EVENT);
    },

    'auto-create is OFF by default: winning a deal creates no project' => function () use ($boot, $ctx, $win, $linked) {
        $a = $boot();
        $a->as($a->admin);
        LiteAssert::same(['auto_create_on_deal_won' => 0], ProjectsLite::service($a)->settings($a->admin));
        $win($a, $ctx->deals[0]);
        LiteAssert::same([], $linked($ctx->deals[0]));
    },

    'settings page: org admin saves; member is refused' => function () use ($boot) {
        $a = $boot();
        $a->as($a->admin);
        LiteAssert::same(200, $a->request('GET', '/projects/settings')['status']);
        $a->request('POST', '/projects/settings', [], ['auto_create_on_deal_won' => '1']);
        LiteAssert::same(1, ProjectsLite::service($a)->settings($a->admin)['auto_create_on_deal_won']);
        $a->request('POST', '/projects/settings', [], []);
        LiteAssert::same(0, ProjectsLite::service($a)->settings($a->admin)['auto_create_on_deal_won']);
        $a->as($a->member);
        LiteAssert::same('forbidden', $a->outcome('GET', '/projects/settings'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/projects/settings', [], ['auto_create_on_deal_won' => '1']));
        $a->as($a->admin);
        LiteAssert::same(0, ProjectsLite::service($a)->settings($a->admin)['auto_create_on_deal_won'], 'member could not switch it on');
    },

    'ON: winning creates exactly one draft project, linked, prefilled, owned by the deal owner; ProjectCreated fired' => function () use ($boot, $ctx, $win, $linked, $setting) {
        $a = $boot();
        $a->as($a->admin);
        $setting($a, true);
        $seen = new \ArrayObject();
        $a->container()->make(EventBus::class)->listen(\Modules\Projects\Events\ProjectCreated::class, new class ($seen) implements \App\Core\Events\ListenerInterface {
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function handle(EventInterface $event): void
            {
                $this->seen[] = $event;
            }
        });
        $dealId = $ctx->deals[1];
        $win($a, $dealId);
        $ids = $linked($dealId);
        LiteAssert::same(1, count($ids));
        $p = $a->pdo->query("SELECT * FROM projects WHERE id = {$ids[0]}")->fetch(\PDO::FETCH_ASSOC);
        $deal = $ctx->rows[$dealId];
        LiteAssert::same('draft', $p['status']);
        LiteAssert::same(mb_substr((string) $deal['name'], 0, 255), $p['name']);
        LiteAssert::same($deal['contact_id'] === null ? null : (int) $deal['contact_id'], $p['contact_id'] === null ? null : (int) $p['contact_id']);
        $ownerActive = $deal['owner_id'] !== null && $a->pdo->query("SELECT status FROM users WHERE id = {$deal['owner_id']} AND organization_id = {$a->orgId}")->fetchColumn() === 'active';
        LiteAssert::same($ownerActive ? (int) $deal['owner_id'] : (int) $a->admin['id'], (int) $p['owner_id'], 'deal owner as manager');
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$ids[0]} AND type = 'created'")->fetchColumn());
        LiteAssert::same(1, count(array_filter((array) $seen, fn ($e) => $e->projectId === (int) $ids[0])), 'ProjectCreated');

        // Won again (re-opened, then won) → still exactly one.
        $win($a, $dealId);
        LiteAssert::same($ids, $linked($dealId), 'one project per deal');
        $setting($a, false);
    },

    'a deal that already has a (manually) linked project gets no auto project' => function () use ($boot, $ctx, $win, $linked, $setting) {
        $a = $boot();
        $a->as($a->admin);
        $dealId = $ctx->deals[2];
        $manual = ProjectsLite::service($a)->createLinked($a->admin, ['name' => 'LiteTest DE manual'], 'deal', $dealId);
        $setting($a, true);
        $win($a, $dealId);
        LiteAssert::same([(int) $manual['id']], $linked($dealId));
        $setting($a, false);
    },

    'stage changes write a timeline entry on every linked project (won / lost / moved)' => function () use ($boot, $ctx, $dealService) {
        $a = $boot();
        $a->as($a->admin);
        $dealId = $ctx->deals[0];
        $p = ProjectsLite::service($a)->createLinked($a->admin, ['name' => 'LiteTest DE timeline'], 'deal', $dealId);
        $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->won);
        $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->open);
        if ($ctx->lost !== false) {
            $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->lost);
        }
        $texts = $a->pdo->query("SELECT description FROM timeline_events WHERE subject_type = 'project' AND subject_id = {$p['id']} AND type = 'deal_stage_changed' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        LiteAssert::same('Linked deal won', $texts[0] ?? null);
        LiteAssert::true(str_starts_with((string) ($texts[1] ?? ''), 'Linked deal moved to stage'), json_encode($texts));
        if ($ctx->lost !== false) {
            LiteAssert::same('Linked deal lost', $texts[2] ?? null);
        }
        $dealService($a)->changeStage($a->admin, $dealId, (int) $ctx->open);
    },

    'lost deals never create a project, even with the setting on' => function () use ($boot, $ctx, $dealService, $linked, $setting) {
        $a = $boot();
        if ($ctx->lost === false) {
            throw new LiteSkip('pipeline has no lost stage');
        }
        $a->as($a->admin);
        $a->pdo->exec('DELETE FROM project_links WHERE linked_id = ' . $ctx->deals[1] . " AND linked_type = 'deal'");
        $setting($a, true);
        $dealService($a)->changeStage($a->admin, $ctx->deals[1], (int) $ctx->open);
        $dealService($a)->changeStage($a->admin, $ctx->deals[1], (int) $ctx->lost);
        LiteAssert::same([], $linked($ctx->deals[1]));
        $setting($a, false);
    },

    'Projects not entitled for the organization → the listener does nothing (setting on)' => function () use ($boot, $ctx, $win, $linked, $setting) {
        $a = $boot();
        $a->as($a->admin);
        $a->pdo->exec('DELETE FROM project_links WHERE linked_id = ' . $ctx->deals[1] . " AND linked_type = 'deal'");
        $setting($a, true);
        $a->setOrgEntitled('projects', false);
        try {
            $win($a, $ctx->deals[1]);
            LiteAssert::same([], $linked($ctx->deals[1]));
        } finally {
            $a->setOrgEntitled('projects', true);
            $setting($a, false);
        }
    },

    'unexpected event shape is logged and ignored (no exception, nothing created)' => function () use ($boot, $ctx) {
        $a = $boot();
        $before = (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        $odd = new class ($a->orgId, $ctx->deals[0]) implements EventInterface {
            public function __construct(public readonly int $organizationId, public readonly int $dealId)
            {
            }
        };
        $listener = new \Modules\Projects\Listeners\DealStageChangedListener(new ProjectLinkRepository(), $a->container()->make(TimelineService::class), null, ProjectsLite::service($a));
        $listener->handle($odd);
        LiteAssert::same($before, (int) $a->pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
    },

    'independent of Contracts: each module honours only its own auto-create setting' => function () use ($boot, $ctx, $win, $linked, $setting) {
        $a = $boot();
        $a->as($a->admin);
        $contracts = $a->container()->make(ContractService::class);
        $contractLinks = fn (int $dealId) => (new \Modules\Contracts\ContractLinkRepository())->contractIdsLinkedTo('deal', $dealId);
        $dealId = $ctx->deals[1];
        $a->pdo->exec("DELETE FROM project_links WHERE linked_id = {$dealId} AND linked_type = 'deal'");
        $a->pdo->exec("DELETE FROM contract_links WHERE linked_id = {$dealId} AND linked_type = 'deal'");

        // Contracts on, Projects off → a contract, no project.
        $contracts->saveSettings($a->admin, ['auto_create_on_deal_won' => '1']);
        $setting($a, false);
        $win($a, $dealId);
        LiteAssert::same(1, count($contractLinks($dealId)), 'contract created');
        LiteAssert::same([], $linked($dealId), 'no project');

        // Projects on, Contracts off → a project; Contracts' "one per deal" untouched.
        $contracts->saveSettings($a->admin, ['auto_create_on_deal_won' => '']);
        $setting($a, true);
        $win($a, $dealId);
        LiteAssert::same(1, count($linked($dealId)), 'project created');
        LiteAssert::same(1, count($contractLinks($dealId)), 'still one contract');
        $setting($a, false);
    },

    'manual "New project" from a deal works with the setting off' => function () use ($boot, $ctx) {
        $a = $boot();
        $a->as($a->admin);
        LiteAssert::same(0, ProjectsLite::service($a)->settings($a->admin)['auto_create_on_deal_won']);
        LiteAssert::same(200, $a->request('GET', '/projects/create', ['from_deal' => (string) $ctx->deals[0]])['status']);
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM project_settings')->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM project_links')->fetchColumn());
    },
];
