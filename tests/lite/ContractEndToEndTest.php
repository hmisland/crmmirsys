<?php

declare(strict_types=1);

/**
 * Step 2.10 — E. One full business story, in order, through real routes
 * and the real Sales DealService (this TEST may use Sales; Contracts may
 * not). crm_local only, one transaction, rolled back.
 *
 *  setting on → deal won → draft auto-created & linked → member opens it
 *  from the deal page → admin completes it → submit → approve (numbered)
 *  → note + tag → contact & deal panels → renew → terminate the original
 *  → module off (everything hidden, data kept) → back on (all restored).
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Application\Services\TimelineService;
use App\Core\Events\EventBus;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Contracts\ContractLinkRepository;
use Modules\Contracts\Services\ContractService;
use Modules\Sales\DealRepository;
use Modules\Sales\PipelineRepository;
use Modules\Sales\PipelineStageRepository;
use Modules\Sales\Services\DealService;
use Modules\Sales\Services\PipelineService;
use Tests\Lite\Support\LocalApp;

$h = new \stdClass();
$h->app = null;
$h->reason = '';
$h->state = [];

$boot = function () use ($h): LocalApp {
    if ($h->app !== null) {
        return $h->app;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $deal = $a->pdo->query("SELECT * FROM deals WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $won = $deal ? $a->pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deal['pipeline_id'] . ' AND is_won = 1 LIMIT 1')->fetchColumn() : false;
    $open = $deal ? $a->pdo->query('SELECT id FROM pipeline_stages WHERE pipeline_id = ' . (int) $deal['pipeline_id'] . ' AND is_won = 0 AND is_lost = 0 ORDER BY position, id LIMIT 1')->fetchColumn() : false;
    $contact = $a->pdo->query("SELECT id FROM contacts WHERE organization_id = {$a->orgId} ORDER BY id LIMIT 1")->fetchColumn();
    if (!$deal || $won === false || $open === false || $contact === false || $a->member === null) {
        $h->reason = 'needs a deal with open/won stages, a contact and a second user';
        $a->rollBack();
        throw new LiteSkip($h->reason);
    }
    $a->activateContracts();
    $a->pdo->prepare("UPDATE deals SET stage_id = ?, status = 'open', closed_at = NULL, contact_id = ? WHERE id = ?")->execute([(int) $open, (int) $contact, (int) $deal['id']]);
    $h->state = ['deal' => (int) $deal['id'], 'won' => (int) $won, 'contact' => (int) $contact];
    $h->app = $a;

    return $a;
};
$svc = fn (LocalApp $a): ContractService => $a->container()->make(ContractService::class);
$dealService = fn (LocalApp $a): DealService => new DealService(
    new DealRepository(),
    new PipelineService(new PipelineRepository(), new PipelineStageRepository(), $a->container()->make(AuthorizationEngine::class)),
    $a->container()->make(TimelineService::class),
    $a->container()->make(AuthorizationEngine::class),
    $a->container()->make(EventBus::class),
);
$step = function (string $name, callable $fn) use ($boot, $h): \Closure {
    return function () use ($boot, $h, $fn, $name) {
        $a = $boot();
        if (!empty($h->state['broken'])) {
            throw new LiteSkip('an earlier step failed: ' . $h->state['broken']);
        }
        try {
            $fn($a, $h);
        } catch (\Throwable $e) {
            if (!$e instanceof LiteSkip) {
                $h->state['broken'] = $name;
            }
            throw $e;
        }
    };
};

return [
    'E1. org admin turns on auto-create in settings' => $step('E1', function (LocalApp $a) {
        $a->as($a->admin);
        $r = $a->request('POST', '/contracts/settings', [], ['auto_create_on_deal_won' => '1']);
        LiteAssert::same('/contracts/settings', $r['location']);
    }),

    'E2. the deal is won in Sales → one draft contract is created and linked' => $step('E2', function (LocalApp $a, \stdClass $h) use ($dealService) {
        $dealService($a)->changeStage($a->admin, $h->state['deal'], $h->state['won']);
        $ids = (new ContractLinkRepository())->contractIdsLinkedTo('deal', $h->state['deal']);
        LiteAssert::same(1, count($ids));
        $h->state['contract'] = $ids[0];
        LiteAssert::same('draft', $a->pdo->query('SELECT status FROM contracts WHERE id = ' . $ids[0])->fetchColumn());
    }),

    'E3. the member sees it on the deal page and can open it (view only)' => $step('E3', function (LocalApp $a, \stdClass $h) {
        $a->as($a->member);
        $deal = $a->request('GET', '/deals/' . $h->state['deal'])['body'];
        LiteAssert::contains('href="/contracts/' . $h->state['contract'] . '"', $deal);
        LiteAssert::same('ok', $a->outcome('GET', '/contracts/' . $h->state['contract']));
        LiteAssert::same('forbidden', $a->outcome('GET', '/contracts/' . $h->state['contract'] . '/edit'), 'not the owner');
        $a->as($a->admin);
    }),

    'E4. admin completes the draft (dates, amount) and submits it' => $step('E4', function (LocalApp $a, \stdClass $h) {
        $id = $h->state['contract'];
        $currency = (string) $a->pdo->query('SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $title = (string) $a->pdo->query("SELECT title FROM contracts WHERE id = {$id}")->fetchColumn();
        $r = $a->request('POST', "/contracts/{$id}", [], ['title' => $title, 'contact_id' => (string) $h->state['contact'], 'start_date' => '2026-02-01', 'end_date' => '2027-01-31', 'value' => '250000', 'currency_id' => $currency, 'terms' => 'LiteTest terms']);
        LiteAssert::same("/contracts/{$id}", $r['location']);
        $a->request('POST', "/contracts/{$id}/submit");
        LiteAssert::same('pending_approval', $a->pdo->query("SELECT status FROM contracts WHERE id = {$id}")->fetchColumn());
    }),

    'E5. approver approves → active with a contract number and signing date' => $step('E5', function (LocalApp $a, \stdClass $h) {
        $id = $h->state['contract'];
        $a->request('POST', "/contracts/{$id}/approve", [], ['signed_on' => '2026-01-30']);
        $row = $a->pdo->query("SELECT status, contract_number, signed_at FROM contracts WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same('active', $row['status']);
        LiteAssert::same(1, preg_match('/^CTR-\d{4,}$/', (string) $row['contract_number']));
        LiteAssert::same('2026-01-30 00:00:00', $row['signed_at']);
        $h->state['number'] = $row['contract_number'];
    }),

    'E6. note and tag through Core; timeline tells the whole story' => $step('E6', function (LocalApp $a, \stdClass $h) {
        $id = $h->state['contract'];
        $a->request('POST', "/records/contract/{$id}/notes", [], ['body' => 'LiteTest signed copy received']);
        $a->request('POST', "/records/contract/{$id}/tags", [], ['name' => 'lite-e2e']);
        $types = $a->pdo->query("SELECT type FROM timeline_events WHERE subject_type = 'contract' AND subject_id = {$id} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['created', 'updated', 'submitted', 'activated', 'note_added', 'tag_added'] as $t) {
            LiteAssert::true(in_array($t, $types, true), "timeline has {$t}: " . implode(',', $types));
        }
        $page = $a->request('GET', "/contracts/{$id}")['body'];
        LiteAssert::contains($h->state['number'], $page);
        LiteAssert::contains('LiteTest signed copy received', $page);
        LiteAssert::contains('lite-e2e', $page);
    }),

    'E7. contact and deal pages both show the contract' => $step('E7', function (LocalApp $a, \stdClass $h) {
        $contact = $a->request('GET', '/contacts/' . $h->state['contact'])['body'];
        $deal = $a->request('GET', '/deals/' . $h->state['deal'])['body'];
        LiteAssert::contains('href="/contracts/' . $h->state['contract'] . '"', $contact);
        LiteAssert::contains('href="/contracts/' . $h->state['contract'] . '"', $deal);
    }),

    'E8. renew → new linked draft with following dates; then terminate the original' => $step('E8', function (LocalApp $a, \stdClass $h) {
        $id = $h->state['contract'];
        $r = $a->request('POST', "/contracts/{$id}/renew");
        $renewal = (int) preg_replace('#^/contracts/(\d+)$#', '$1', (string) $r['location']);
        $row = $a->pdo->query("SELECT status, relation, parent_contract_id, start_date, end_date FROM contracts WHERE id = {$renewal}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same(['draft', 'renewal', (string) $id, '2027-02-01', '2028-01-31'], [$row['status'], $row['relation'], (string) $row['parent_contract_id'], $row['start_date'], $row['end_date']]);
        $a->request('POST', "/contracts/{$id}/terminate", [], ['reason' => 'replaced by renewal']);
        LiteAssert::same('terminated', $a->pdo->query("SELECT status FROM contracts WHERE id = {$id}")->fetchColumn());
        $h->state['renewal'] = $renewal;
        LiteAssert::contains('href="/contracts/' . $renewal . '"', $a->request('GET', "/contracts/{$id}")['body'], 'history shows the renewal');
    }),

    'E9. Contracts switched off for the organization: hidden everywhere, data kept' => $step('E9', function (LocalApp $a, \stdClass $h) {
        $a->setOrgEntitled('contracts', false);
        LiteAssert::same('notfound', $a->outcome('GET', '/contracts/' . $h->state['contract']));
        LiteAssert::notContains('data-related-panel="contracts.', $a->request('GET', '/contacts/' . $h->state['contact'])['body']);
        LiteAssert::notContains('data-related-panel="contracts.', $a->request('GET', '/deals/' . $h->state['deal'])['body']);
        LiteAssert::same(2, (int) $a->pdo->query('SELECT COUNT(*) FROM contracts WHERE id IN (' . $h->state['contract'] . ',' . $h->state['renewal'] . ')')->fetchColumn());
    }),

    'E10. switched back on: everything is back as it was' => $step('E10', function (LocalApp $a, \stdClass $h) use ($svc) {
        $a->setOrgEntitled('contracts', true);
        LiteAssert::same('ok', $a->outcome('GET', '/contracts/' . $h->state['contract']));
        LiteAssert::contains('href="/contracts/' . $h->state['contract'] . '"', $a->request('GET', '/deals/' . $h->state['deal'])['body']);
        LiteAssert::same(1, count($svc($a)->links($a->admin, $h->state['contract'])));
        LiteAssert::same('terminated', $svc($a)->find($a->admin, $h->state['contract'])['status']);
    }),

    'E11. rollback: the real deal and all test data are back to their original state' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM contract_settings')->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM tags WHERE name = 'lite-e2e'")->fetchColumn());
    },
];
