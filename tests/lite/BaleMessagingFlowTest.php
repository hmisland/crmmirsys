<?php

declare(strict_types=1);

/**
 * Bale (Safir) messaging end to end through the real router, middleware,
 * Marketing services, queue worker and database (crm_local only, one
 * transaction, rolled back at the end). Safir is ALWAYS mocked
 * (FakeSafirTransport): no real message is sent, no real key is used.
 *
 * Tests run in order and share fixtures.
 */

use App\Core\Exceptions\NotFoundException;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\SecretBox;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\QueueWorker;
use Modules\MarketingBale\Channels\BaleChannel;
use Modules\MarketingBale\Safir\SafirClient;
use Modules\MarketingBale\Safir\SafirHttpResponse;
use Modules\MarketingBale\Services\BaleSettingsService;
use Tests\Lite\Fixtures\FakeSafirTransport;
use Tests\Lite\Fixtures\RecordingBillableMessageSink;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Fixtures/BaleFakes.php';

$h = new \stdClass();
$h->ready = false;
$h->reason = '';
$h->key = 'lite-safir-KEY-7781';
$h->text = "سلام {{first_name}}، پیشنهاد ویژهٔ این هفته: ۲۰٪ تخفیف 🎉";
$h->financeTables = ['wallets', 'wallet_ledger', 'wallet_transactions', 'wallet_withdrawal_requests', 'notification_credits', 'notification_credit_transactions', 'invoices', 'payments', 'credit_notes', 'finance_expenses'];

/** marketing_messages of a campaign keyed by address (numeric addresses become int keys in PHP arrays, so compare with (string)). */
$rowsOf = fn (int $campaignId) => (function () use ($h, $campaignId): array {
    $stmt = $h->a->pdo->prepare('SELECT * FROM marketing_messages WHERE campaign_id = ? ORDER BY id');
    $stmt->execute([$campaignId]);
    $byAddress = [];
    foreach ($stmt->fetchAll() as $r) {
        $byAddress[(string) $r['address']] = $r;
    }

    return $byAddress;
})();

/** A fresh BaleChannel over the fake transport, registered as "bale" in Marketing's registry. */
$useChannel = function (array $options = []) use ($h): BaleChannel {
    $c = $h->a->container();
    $channel = new BaleChannel(
        new SafirClient($h->safir),
        $c->make(BaleSettingsService::class),
        $h->sink,
        null,
        (float) ($options['rps'] ?? 0),
        (int) ($options['per_run'] ?? 200),
        600,
        60,
        (int) ($options['approvals'] ?? 20),
        $options['sleep'] ?? null,
    );
    $c->make(ChannelRegistry::class)->register($channel);

    return $channel;
};

$setup = function () use ($h, $useChannel): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT 1 FROM marketing_messages LIMIT 1');
        $h->a->pdo->query('SELECT 1 FROM organization_bale_settings LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or marketing/bale not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    if (!$c->has(BaleSettingsService::class) || !$c->has(ChannelRegistry::class)) {
        throw new \RuntimeException('MarketingBale did not register (Marketing ChannelRegistry missing?)');
    }

    foreach ($h->financeTables as $table) {
        $h->financeBefore[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'bale_messaging')");
    foreach (['marketing' => ['Marketing', '[]'], 'bale_messaging' => ['Bale Messaging', '["marketing"]']] as $key => [$name, $deps]) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', ?)")->execute([$key, $name, $deps]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 0)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    }
    $pdo->prepare('DELETE FROM organization_bale_settings WHERE organization_id = ?')->execute([$a->orgId]);

    // Safir answers by number: 9999 → 17 NotBaleUser, 7777 → 8 InvalidPhone, else accepted.
    $h->byPhone = [];
    $h->safir = new FakeSafirTransport();
    $h->defaultResponder = function (array $body) use ($h): SafirHttpResponse {
        $phone = (string) ($body['phone_number'] ?? '');
        if (isset($h->byPhone[$phone])) {
            return ($h->byPhone[$phone])($body);
        }

        return FakeSafirTransport::accepted('lite-' . (++$h->safir->seq));
    };
    $h->safir->responder = $h->defaultResponder;
    $h->byPhone['989129999999'] = fn () => FakeSafirTransport::error(17);
    $h->byPhone['989127777777'] = fn () => FakeSafirTransport::error(8);
    $h->sink = new RecordingBillableMessageSink();
    $useChannel();

    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteBale group']);
    $h->groupId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, category_id, status) VALUES (?, ?, 'Lite', ?, ?, 'active')");
    $h->c = [];
    foreach ([
        1 => ['Sara', '09121111111', true],
        2 => ['Reza', '+98 912 222 2222', true],
        3 => ['Invalid', '12345', true],
        4 => ['Nobody', null, true],
        5 => ['Twin', '0912 111 1111', true],
        6 => ['Abroad', '+447911123456', true],
        7 => ['MutedBale', '09126666666', true],
        8 => ['MutedSms', '09128888888', true],
        9 => ['NoBale', '09129999999', true],
        10 => ['BadPhone', '09127777777', true],
        11 => ['R1', '09131000001', false],
        12 => ['R2', '09131000002', false],
        13 => ['R3', '09131000003', false],
        14 => ['R4', '09131000004', false],
    ] as $n => [$first, $phone, $inGroup]) {
        $insert->execute([$a->orgId, $first, $phone, $inGroup ? $h->groupId : null]);
        $h->c[$n] = (int) $pdo->lastInsertId();
    }
    // Opt-outs: Bale list (honoured) and SMS list (D11: NOT applied to Bale).
    $pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'bale', '989126666666', 'manual', 'user')")->execute([$a->orgId]);
    $pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'sms', '09128888888', 'manual', 'user')")->execute([$a->orgId]);

    $h->otherContact = null;
    if ($a->otherOrgId !== null) {
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, 'Foreign', '09125555555', 'active')")->execute([$a->otherOrgId]);
        $h->otherContact = (int) $pdo->lastInsertId();
    }

    $h->worker = $c->make(QueueWorker::class);
    $h->campaigns = $c->make(CampaignService::class);
    $h->settings = $c->make(BaleSettingsService::class);
    $h->ready = true;
};

$entitle = function (string $key, bool $on) use ($h): void {
    $h->a->setOrgEntitled($key, $on);
    $h->a->container()->make(ModuleGate::class)->reset();
};

$draft = function (array $input) use ($h): int {
    $h->a->as($h->a->admin);
    $r = $h->a->request('POST', '/marketing/campaigns', [], $input + ['channel' => 'bale', 'message_class' => 'marketing', 'body_text' => $h->text]);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('draft not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};

$confirmCount = fn (int $id) => $h->campaigns->review($h->a->admin, $h->campaigns->find($h->a->admin, $id))['confirm_count'];

$approve = function (int $id, ?int $count = null, bool $consent = true) use ($h, $confirmCount): array {
    $h->a->as($h->a->admin);
    $count ??= $confirmCount($id);

    return $h->a->request('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => (string) $count] + ($consent ? ['confirm_consent' => '1'] : []));
};

/** The worker memoizes entitlements per process (one cron run); a test that flips them mid-run resets it. */
$resetMemo = fn () => (new \ReflectionProperty(QueueWorker::class, 'entitlements'))->getValue($h->worker)->reset();

$run = fn (int $id) => $h->worker->processCampaign($id, 100, microtime(true) + 20);

$status = fn (int $id) => (string) $h->a->pdo->query('SELECT status FROM marketing_campaigns WHERE id = ' . $id)->fetchColumn();

$clearPause = function () use ($h): void {
    $h->a->pdo->prepare('UPDATE organization_bale_settings SET paused_until = NULL, pause_reason = NULL, last_error = NULL WHERE organization_id = ?')->execute([$h->a->orgId]);
};

$due = function (int $campaignId) use ($h): void {
    $h->a->pdo->prepare('UPDATE marketing_messages SET next_attempt_at = NULL WHERE campaign_id = ?')->execute([$campaignId]);
};

$contactsCampaign = fn (array $contactNumbers, string $name) => $draft(['name' => $name, 'audience_type' => 'contacts', 'contact_ids' => array_map(fn ($n) => $h->c[$n], $contactNumbers)]);

return [
    'entitlement: without bale_messaging the channel is absent, the Bale card is muted and its POSTs refused server-side; Safir never called' => function () use ($setup, $h, $entitle) {
        $setup();
        $a = $h->a;
        $entitle('marketing', true);
        $a->as($a->admin);

        $page = $a->request('GET', '/org/messaging')['body'];
        LiteAssert::true(preg_match('#<section class="card card--form provider-card provider-card--unavailable" id="provider-bale">#', $page) === 1, 'Bale card visible but muted');
        LiteAssert::false(in_array('bale', $h->campaigns->availableChannels($a->admin), true));

        $a->request('POST', '/org/messaging/bale', [], ['api_key' => $h->key, 'bot_id' => '4242']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM organization_bale_settings WHERE organization_id = {$a->orgId}")->fetchColumn(), 'nothing stored');

        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'x', 'channel' => 'bale', 'message_class' => 'marketing', 'body_text' => 'x', 'audience_type' => 'group', 'group_id' => (string) $h->groupId]);
        LiteAssert::same(422, $r['status'], 'bale campaign refused without the module');

        // Bale entitled but Marketing off: Marketing routes 404 and the card stays refused.
        $entitle('marketing', false);
        $entitle('bale_messaging', true);
        LiteAssert::same('notfound', $a->outcome('GET', '/marketing'));
        $a->request('POST', '/org/messaging/bale', [], ['api_key' => $h->key, 'bot_id' => '4242']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM organization_bale_settings WHERE organization_id = {$a->orgId}")->fetchColumn(), 'both modules required');
        LiteAssert::same(0, count($h->safir->requests));
        $entitle('marketing', true);
    },

    'settings: organization.edit required; bad input refused; key encrypted + hinted, never rendered; empty key keeps it; no Safir call; status "configured — not verified"' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('POST', '/org/messaging/bale', [], ['api_key' => $h->key, 'bot_id' => '4242']));

        $a->as($a->admin);
        foreach ([['bot_id' => 'abc', 'api_key' => $h->key], ['bot_id' => '0', 'api_key' => $h->key], ['bot_id' => '-5', 'api_key' => $h->key], ['bot_id' => '4242', 'api_key' => 'short'], ['bot_id' => '4242', 'api_key' => 'has space inside'], ['bot_id' => '4242', 'api_key' => '']] as $bad) {
            $a->request('POST', '/org/messaging/bale', [], $bad);
        }
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM organization_bale_settings WHERE organization_id = {$a->orgId} AND api_key_encrypted IS NOT NULL")->fetchColumn());

        $a->request('POST', '/org/messaging/bale', [], ['api_key' => $h->key, 'bot_id' => '4242']);
        $row = $a->pdo->query("SELECT * FROM organization_bale_settings WHERE organization_id = {$a->orgId}")->fetch();
        LiteAssert::same('4242', (string) $row['bot_id']);
        LiteAssert::notContains($h->key, (string) $row['api_key_encrypted']);
        LiteAssert::same($h->key, SecretBox::decrypt($row['api_key_encrypted']));
        LiteAssert::same('7781', $row['api_key_hint']);
        LiteAssert::same('configured', $row['status']);
        LiteAssert::same(null, $h->settings->unavailableReason(), 'ready to send');

        $view = $h->settings->view();
        LiteAssert::false(in_array($h->key, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $view), true), 'view never carries the key');
        $page = $a->request('GET', '/org/messaging')['body'];
        LiteAssert::notContains($h->key, $page);
        LiteAssert::contains('••••7781', $page);
        LiteAssert::contains('Configured — not verified', $page);
        LiteAssert::notContains('provider-card--unavailable" id="provider-bale"', $page, 'entitled card is active');

        $a->request('POST', '/org/messaging/bale', [], ['api_key' => '', 'bot_id' => '4242']);
        LiteAssert::same($h->key, SecretBox::decrypt($a->pdo->query("SELECT api_key_encrypted FROM organization_bale_settings WHERE organization_id = {$a->orgId}")->fetchColumn()), 'empty key keeps the stored one');
        LiteAssert::same(0, count($h->safir->requests), 'saving never contacts Safir');
    },

    'review: group audience — eligible, invalid, missing, duplicate, unsupported (foreign), Bale opt-out honoured, SMS opt-out not applied (D11)' => function () use ($setup, $h, $draft) {
        $setup();
        $h->groupCampaign = $draft(['name' => 'LiteBale group', 'audience_type' => 'group', 'group_id' => (string) $h->groupId]);
        $review = $h->campaigns->review($h->a->admin, $h->campaigns->find($h->a->admin, $h->groupCampaign));
        $t = $review['totals'];
        LiteAssert::same(10, $t['contacts']);
        LiteAssert::same(5, $t['eligible'], 'Sara, Reza, MutedSms, NoBale, BadPhone');
        LiteAssert::same(1, $t['invalid_address']);
        LiteAssert::same(1, $t['missing_address']);
        LiteAssert::same(1, $t['duplicate']);
        LiteAssert::same(1, $t['unsupported']);
        LiteAssert::same(1, $t['suppressed']);
        LiteAssert::same(0, $review['sms_parts']);
        LiteAssert::true(in_array('marketing.warning.unsupported', $review['warnings'], true));
        LiteAssert::same(null, $review['channel_error']);

        $h->a->as($h->a->admin);
        $page = $h->a->request('GET', '/marketing/campaigns/' . $h->groupCampaign)['body'];
        LiteAssert::contains('name="confirm_consent"', $page, 'consent checkbox on the approval form');
        LiteAssert::contains('Unsupported recipients', $page);
    },

    'approval: consent confirmation required; count must still match; a second approval is refused; recipients snapshotted with skip reasons' => function () use ($setup, $h, $approve, $status) {
        $setup();
        $id = $h->groupCampaign;
        LiteAssert::same(422, $approve($id, null, false)['status'], 'no consent → refused');
        LiteAssert::same('draft', $status($id));
        LiteAssert::same(422, $approve($id, 4)['status'], 'count changed → refused');
        LiteAssert::same('draft', $status($id));
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());

        LiteAssert::same(302, $approve($id)['status']);
        LiteAssert::same('queued', $status($id));
        LiteAssert::same(422, $approve($id)['status'], 'second approval refused');

        $stmt = $h->a->pdo->query("SELECT address, status, skip_reason FROM marketing_messages WHERE campaign_id = {$id} ORDER BY id");
        $byStatus = [];
        foreach ($stmt->fetchAll() as $r) {
            $byStatus[$r['status'] . ':' . ($r['skip_reason'] ?? '')][] = (string) $r['address'];
        }
        LiteAssert::same(5, count($byStatus['pending:'] ?? []));
        LiteAssert::true(in_array('989128888888', $byStatus['pending:'], true), 'SMS opt-out not applied to Bale');
        LiteAssert::same(['+447911123456'], $byStatus['skipped:unsupported_recipient'] ?? []);
        LiteAssert::same(['989126666666'], $byStatus['skipped:suppressed_manual'] ?? []);
        LiteAssert::same(1, count($byStatus['skipped:invalid_address'] ?? []));
        LiteAssert::same(0, count($h->safir->requests), 'approval never sends');
    },

    'worker: accepted / NotBaleUser (17) → unsupported / InvalidPhone (8) → failed; opt-out added after approval honoured at send time; stable request ids; report counts' => function () use ($setup, $h, $run, $rowsOf) {
        $setup();
        $id = $h->groupCampaign;
        // Opt-out arriving after approval (Reza): skipped at send time, never sent.
        $h->a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'bale', '989122222222', 'unsubscribed', 'user')")->execute([$h->a->orgId]);

        $result = $run($id);
        LiteAssert::same('completed', $result['status']);
        LiteAssert::same(4, count($h->safir->requests), 'Sara, MutedSms, NoBale, BadPhone');
        $sentTo = array_map(fn ($r) => $r['body']['phone_number'], $h->safir->requests);
        LiteAssert::false(in_array('989122222222', $sentTo, true), 'send-time suppression');
        LiteAssert::false(in_array('989126666666', $sentTo, true));

        $rows = $rowsOf($id);
        foreach ($h->safir->requests as $req) {
            $row = $rows[$req['body']['phone_number']];
            LiteAssert::same(4242, $req['body']['bot_id']);
            LiteAssert::true(in_array('api-access-key: ' . $h->key, $req['headers'], true));
            LiteAssert::true(preg_match('/^crm-(\d+)$/', $req['body']['request_id'], $m) === 1);
            LiteAssert::same((int) $row['id'], (int) ((int) $m[1] % 1_000_000_000_000), 'request_id = installation prefix + message id');
        }
        $saraText = $h->safir->requests[0]['body']['message_data']['message']['text'];
        LiteAssert::same('سلام Sara، پیشنهاد ویژهٔ این هفته: ۲۰٪ تخفیف 🎉', $saraText, 'Persian text personalized and intact');

        LiteAssert::same('sent', $rows['989121111111']['status']);
        LiteAssert::true(str_starts_with((string) $rows['989121111111']['provider_message_id'], 'lite-'));
        LiteAssert::same('safir', $rows['989121111111']['provider']);
        LiteAssert::same(null, $rows['989121111111']['delivery_status'], 'never "delivered"');
        LiteAssert::same('sent', $rows['989128888888']['status']);
        LiteAssert::same('skipped', $rows['989129999999']['status']);
        LiteAssert::same('unsupported_recipient', $rows['989129999999']['skip_reason']);
        LiteAssert::same('failed', $rows['989127777777']['status']);
        LiteAssert::same('0', (string) $rows['989127777777']['ambiguous']);
        LiteAssert::same('Safir: safir_8_invalid_phone (HTTP 200)', $rows['989127777777']['error']);
        LiteAssert::same('suppressed_unsubscribed', $rows['989122222222']['skip_reason']);

        $counts = (new MessageRepository())->countsForCampaign($id);
        LiteAssert::same(2, $counts['status']['sent']);
        LiteAssert::same(1, $counts['status']['failed']);
        LiteAssert::same(2, $counts['unsupported'], 'foreign number + no Bale account');
        LiteAssert::same(0, $counts['unknown']);
        LiteAssert::same([], $counts['delivery']);

        LiteAssert::same('verified', $h->settings->view()['status'], 'credentials verified by an accepted message');
        LiteAssert::true($h->settings->view()['last_success_at'] !== null);

        $h->a->as($h->a->admin);
        $page = $h->a->request('GET', '/marketing/campaigns/' . $id)['body'];
        LiteAssert::contains('Bale/Safir provides no delivery or read reports', $page);
        LiteAssert::contains('data-count="unknown"', $page);
        LiteAssert::notContains('data-count="delivered"', $page, 'no delivered counter for Bale');
        LiteAssert::contains('Unsupported recipient (no Bale account or non-Iranian number)', $page);

        // Billing seam: one event per final Safir outcome, unique keys.
        $kinds = array_count_values(array_column($h->sink->events, 'outcome'));
        LiteAssert::same(2, $kinds['accepted'] ?? 0);
        LiteAssert::same(1, $kinds['unsupported'] ?? 0);
        LiteAssert::same(1, $kinds['failed'] ?? 0);
        LiteAssert::same(0, $h->sink->duplicates);
        foreach ($h->sink->events as $key => $event) {
            LiteAssert::same('bale:' . $event['request_id'] . ':' . $event['outcome'], (string) $key);
            LiteAssert::same($h->a->orgId, $event['organization_id']);
            LiteAssert::same($id, $event['campaign_id']);
        }
    },

    'rate limit (code 3): batch stops after one request, row back in the queue with its attempt counted, organization paused; resumes later with the SAME request_id' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $clearPause, $due, $rowsOf, $status) {
        $setup();
        $id = $contactsCampaign([11, 12, 13], 'LiteBale rate');
        $approve($id);
        $before = count($h->safir->requests);
        $limited = true;
        $h->safir->responder = function (array $body) use ($h, &$limited) {
            if ($limited) {
                $limited = false;

                return FakeSafirTransport::error(3);
            }

            return ($h->defaultResponder)($body);
        };

        $run($id);
        LiteAssert::same($before + 1, count($h->safir->requests), 'stopped after the rate-limit answer');
        $firstRequest = $h->safir->last()['body'];
        $rows = $rowsOf($id);
        $limitedRow = $rows[$firstRequest['phone_number']];
        LiteAssert::same('pending', $limitedRow['status']);
        LiteAssert::same('1', (string) $limitedRow['attempts']);
        LiteAssert::true($limitedRow['next_attempt_at'] !== null);
        foreach ($rows as $address => $r) {
            if ((string) $address !== $firstRequest['phone_number']) {
                LiteAssert::same('pending', $r['status']);
                LiteAssert::same('0', (string) $r['attempts'], 'untouched rows keep their attempts');
            }
        }
        LiteAssert::same('safir_3_rate_limited', $h->settings->view()['pause_reason']);

        $blocked = $run($id);
        LiteAssert::same('bale.pause.safir_3_rate_limited', $blocked['error']);
        LiteAssert::same($before + 1, count($h->safir->requests), 'paused: no request');

        $clearPause();
        $due($id);
        $run($id);
        LiteAssert::same('completed', $status($id));
        $resent = array_values(array_filter(array_slice($h->safir->requests, $before + 1), fn ($r) => $r['body']['phone_number'] === $firstRequest['phone_number']));
        LiteAssert::same(1, count($resent));
        LiteAssert::same($firstRequest['request_id'], $resent[0]['body']['request_id'], 'same request_id on the safe retry');
        $h->safir->responder = $h->defaultResponder;
    },

    'payment required (20) and contact limit (21): nothing marked failed, no attempt used, batch stops, campaign shows the reason; resumes after the pause' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $clearPause, $due, $rowsOf, $status) {
        $setup();
        $id = $contactsCampaign([11, 12], 'LiteBale pause');
        $approve($id);
        foreach ([20 => 'safir_20_payment_required', 21 => 'safir_21_contact_limit'] as $code => $reason) {
            $before = count($h->safir->requests);
            $h->safir->responder = fn () => FakeSafirTransport::error($code);
            $run($id);
            LiteAssert::same($before + 1, count($h->safir->requests), "code {$code}: one request, then stop");
            foreach ($rowsOf($id) as $r) {
                LiteAssert::same('pending', $r['status'], "code {$code}: still queued");
                LiteAssert::same('0', (string) $r['attempts'], "code {$code}: no attempt used");
            }
            LiteAssert::same('bale.pause.' . $reason, (string) $h->a->pdo->query("SELECT last_error FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
            LiteAssert::same($reason, $h->settings->view()['pause_reason']);
            LiteAssert::same('bale.pause.' . $reason, $run($id)['error'], 'org paused');
            LiteAssert::same($before + 1, count($h->safir->requests));
            $clearPause();
            $due($id);
        }
        $h->safir->responder = $h->defaultResponder;
        $run($id);
        LiteAssert::same('completed', $status($id));
        LiteAssert::same('', (string) $h->a->pdo->query("SELECT COALESCE(last_error, '') FROM marketing_campaigns WHERE id = {$id}")->fetchColumn(), 'reason cleared once sending works');
    },

    'key rejected (HTTP 401): credentials marked rejected, sending blocked with no further requests until a new key is saved' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $due, $status) {
        $setup();
        $id = $contactsCampaign([13, 14], 'LiteBale 401');
        $approve($id);
        $before = count($h->safir->requests);
        $h->safir->responder = fn () => new SafirHttpResponse(401, '{"detail":"invalid key"}');
        $run($id);
        LiteAssert::same($before + 1, count($h->safir->requests));
        LiteAssert::same('rejected', $h->settings->view()['status']);
        LiteAssert::same('bale.error.credentials_rejected', $run($id)['error']);
        LiteAssert::same($before + 1, count($h->safir->requests), 'no request with a known-bad key');
        LiteAssert::same(2, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'pending'")->fetchColumn());

        $h->safir->responder = $h->defaultResponder;
        $h->a->as($h->a->admin);
        $h->a->request('POST', '/org/messaging/bale', [], ['api_key' => $h->key, 'bot_id' => '4242']);
        LiteAssert::same('configured', $h->settings->view()['status']);
        $due($id);
        $run($id);
        LiteAssert::same('completed', $status($id));
    },

    'unknown outcomes (timeout after send, HTTP 5xx, code 2, malformed answer): failed + ambiguous, never retried — not by the worker, not by "Retry failed"' => function () use ($setup, $h, $draft, $approve, $run, $due) {
        $setup();
        $pdo = $h->a->pdo;
        $insert = $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, ?, ?, 'active')");
        $ids = [];
        foreach (['U1' => '09141000001', 'U2' => '09141000002', 'U3' => '09141000003', 'U4' => '09141000004'] as $name => $phone) {
            $insert->execute([$h->a->orgId, $name, $phone]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $h->byPhone['989141000001'] = fn () => SafirHttpResponse::lost(28);
        $h->byPhone['989141000002'] = fn () => new SafirHttpResponse(502, '<html>bad gateway</html>');
        $h->byPhone['989141000003'] = fn () => FakeSafirTransport::error(2);
        $h->byPhone['989141000004'] = fn () => new SafirHttpResponse(200, '{}');
        $id = $draft(['name' => 'LiteBale unknown', 'audience_type' => 'contacts', 'contact_ids' => $ids]);
        $approve($id);
        $before = count($h->safir->requests);
        $run($id);
        LiteAssert::same($before + 4, count($h->safir->requests));
        LiteAssert::same(4, (int) $pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'failed' AND ambiguous = 1")->fetchColumn());
        LiteAssert::same(4, (new MessageRepository())->countsForCampaign($id)['unknown']);
        $h->unknownRequestIds = [];
        foreach (array_slice($h->safir->requests, $before) as $req) {
            $h->unknownRequestIds[(string) $req['body']['phone_number']] = $req['body']['request_id'];
        }

        $h->a->as($h->a->admin);
        $h->a->request('POST', "/marketing/campaigns/{$id}/retry");
        LiteAssert::same(0, (int) $pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'pending'")->fetchColumn(), 'Retry failed leaves unknown rows alone');
        $due($id);
        $run($id);
        LiteAssert::same($before + 4, count($h->safir->requests), 'never resent automatically');
        $h->unknownCampaign = $id;
        $page = $h->a->request('GET', '/marketing/campaigns/' . $id)['body'];
        LiteAssert::contains('/retry-unknown', $page, 'explicit resend offered per row');
        LiteAssert::contains('name="confirm_duplicate_risk"', $page);
    },

    'unknown → resend only by explicit, confirmed action of a marketing.send user; the SAME request_id is reused' => function () use ($setup, $h, $run, $status) {
        $setup();
        $id = $h->unknownCampaign;
        $row = $h->a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id} AND address = '989141000003'")->fetch();
        $url = "/marketing/campaigns/{$id}/messages/{$row['id']}/retry-unknown";

        $h->a->as($h->a->admin);
        $h->a->request('POST', $url, [], []);
        LiteAssert::same('failed', (string) $h->a->pdo->query("SELECT status FROM marketing_messages WHERE id = {$row['id']}")->fetchColumn(), 'no confirmation → nothing');
        if ($h->a->member !== null) {
            $h->a->as($h->a->member);
            LiteAssert::same('forbidden', $h->a->outcome('POST', $url, [], ['confirm_duplicate_risk' => '1']), 'member has no marketing.send');
        }
        $h->a->as($h->a->noRole);
        LiteAssert::same('forbidden', $h->a->outcome('POST', $url, [], ['confirm_duplicate_risk' => '1']));

        $h->a->as($h->a->admin);
        $h->a->request('POST', $url, [], ['confirm_duplicate_risk' => '1']);
        $after = $h->a->pdo->query("SELECT status, ambiguous FROM marketing_messages WHERE id = {$row['id']}")->fetch();
        LiteAssert::same('pending', $after['status']);
        LiteAssert::same('sending', $status($id));
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'marketing_campaign' AND subject_id = {$id} AND type = 'marketing.campaign_retry_unknown'")->fetchColumn(), 'audited');

        unset($h->byPhone['989141000003']);
        $before = count($h->safir->requests);
        $run($id);
        LiteAssert::same($before + 1, count($h->safir->requests), 'only that row');
        LiteAssert::same($h->unknownRequestIds['989141000003'], $h->safir->last()['body']['request_id'], 'same request_id');
        LiteAssert::same('sent', (string) $h->a->pdo->query("SELECT status FROM marketing_messages WHERE id = {$row['id']}")->fetchColumn());

        // A row that is not unknown cannot be pushed through this path.
        $h->a->request('POST', $url, [], ['confirm_duplicate_risk' => '1']);
        LiteAssert::same('sent', (string) $h->a->pdo->query("SELECT status FROM marketing_messages WHERE id = {$row['id']}")->fetchColumn());
    },

    'connection failure before sending: bounded automatic retry (3 attempts) with the same request_id, then a definite failure that "Retry failed" may requeue' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $due) {
        $setup();
        $h->safir->responder = fn () => SafirHttpResponse::notSent(7);
        $id = $contactsCampaign([11], 'LiteBale connect');
        $approve($id);
        $before = count($h->safir->requests);
        for ($i = 0; $i < 4; $i++) {
            $run($id);
            $due($id);
        }
        LiteAssert::same($before + 3, count($h->safir->requests), 'MAX_ATTEMPTS = 3');
        $ids = array_unique(array_map(fn ($r) => $r['body']['request_id'], array_slice($h->safir->requests, $before)));
        LiteAssert::same(1, count($ids), 'one request_id for every attempt');
        $row = $h->a->pdo->query("SELECT status, ambiguous FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('failed', $row['status']);
        LiteAssert::same('0', (string) $row['ambiguous'], 'definitely not sent');

        $h->safir->responder = $h->defaultResponder;
        $h->a->as($h->a->admin);
        $h->a->request('POST', "/marketing/campaigns/{$id}/retry");
        $run($id);
        LiteAssert::same('sent', (string) $h->a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    'stale worker recovery: a row left "processing" by a dead worker becomes unknown — never resent' => function () use ($setup, $h, $contactsCampaign, $approve, $run) {
        $setup();
        $id = $contactsCampaign([12, 13], 'LiteBale stale');
        $approve($id);
        $stale = (int) $h->a->pdo->query("SELECT id FROM marketing_messages WHERE campaign_id = {$id} ORDER BY id LIMIT 1")->fetchColumn();
        $h->a->pdo->exec("UPDATE marketing_messages SET status = 'processing', lock_token = 'deadworker', locked_until = '2000-01-01 00:00:00', attempts = 1 WHERE id = {$stale}");
        $h->a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");
        $before = count($h->safir->requests);
        $run($id);
        LiteAssert::same($before + 1, count($h->safir->requests), 'only the other row');
        $row = $h->a->pdo->query("SELECT status, ambiguous, error FROM marketing_messages WHERE id = {$stale}")->fetch();
        LiteAssert::same('failed', $row['status']);
        LiteAssert::same('1', (string) $row['ambiguous']);
        LiteAssert::contains('interrupted_while_sending', (string) $row['error']);
    },

    'throttling: per-run cap and requests-per-second spacing are applied (internal settings, not Safir limits)' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $useChannel, $status) {
        $setup();
        $sleeps = [];
        $useChannel(['per_run' => 2, 'rps' => 5, 'sleep' => function (float $s) use (&$sleeps) {
            $sleeps[] = $s;
        }]);
        $id = $contactsCampaign([11, 12, 13], 'LiteBale throttle');
        $approve($id);
        $before = count($h->safir->requests);
        $run($id);
        LiteAssert::same($before + 2, count($h->safir->requests), 'at most per_run per run');
        LiteAssert::true(count($sleeps) === 1 && $sleeps[0] > 0 && $sleeps[0] <= 0.2 + 1e-6, 'second request waited ≤ 1/5 s');
        $run($id);
        LiteAssert::same('completed', $status($id));
        $useChannel();
    },

    'module disabled or sending paused mid-queue: worker sends nothing, rows stay queued (history kept), resumes afterwards' => function () use ($setup, $h, $contactsCampaign, $approve, $run, $entitle, $status, $resetMemo) {
        $setup();
        $id = $contactsCampaign([11, 14], 'LiteBale disabled');
        $approve($id);
        $before = count($h->safir->requests);

        $entitle('bale_messaging', false);
        $resetMemo();
        LiteAssert::same('marketing.error.module_unavailable', $run($id)['error']);
        LiteAssert::same($before, count($h->safir->requests));
        LiteAssert::same(2, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'pending'")->fetchColumn());
        $entitle('bale_messaging', true);
        $resetMemo();

        $h->a->as($h->a->admin);
        $h->a->request('POST', '/org/messaging/bale/enabled', [], ['enabled' => '0']);
        LiteAssert::same('bale.error.disabled', $run($id)['error']);
        LiteAssert::same($before, count($h->safir->requests));
        $h->a->request('POST', '/org/messaging/bale/enabled', [], ['enabled' => '1']);
        $run($id);
        LiteAssert::same('completed', $status($id));
    },

    'campaign test send: only to the typed number (normalized), with the organization key; never stored as a recipient' => function () use ($setup, $h, $contactsCampaign) {
        $setup();
        $id = $contactsCampaign([11], 'LiteBale test send');
        $before = count($h->safir->requests);
        $rows = (int) $h->a->pdo->query('SELECT COUNT(*) FROM marketing_messages')->fetchColumn();
        $h->a->as($h->a->admin);
        $h->a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '0935 123 4567']);
        LiteAssert::same($before + 1, count($h->safir->requests));
        LiteAssert::same('989351234567', $h->safir->last()['body']['phone_number']);
        LiteAssert::true(in_array('api-access-key: ' . $h->key, $h->safir->last()['headers'], true));
        LiteAssert::same($rows, (int) $h->a->pdo->query('SELECT COUNT(*) FROM marketing_messages')->fetchColumn());
        $h->a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '+44 7911 123456']);
        LiteAssert::same($before + 1, count($h->safir->requests), 'foreign test number refused before Safir');
    },

    'approval rate limit guards against repeated approvals' => function () use ($setup, $h, $contactsCampaign, $approve, $status) {
        $setup();
        $id = $contactsCampaign([12], 'LiteBale approvals');
        $stmt = $h->a->pdo->prepare("INSERT INTO rate_limit_hits (bucket, subject, created_at) VALUES ('marketing.approve.bale', ?, ?)");
        for ($i = 0; $i < 20; $i++) {
            $stmt->execute(['user:' . (int) $h->a->admin['id'], date('Y-m-d H:i:s')]);
        }
        LiteAssert::same(422, $approve($id)['status']);
        LiteAssert::same('draft', $status($id));
        $h->a->pdo->exec("DELETE FROM rate_limit_hits WHERE bucket = 'marketing.approve.bale'");
        LiteAssert::same(302, $approve($id)['status']);
    },

    'isolation: another organization cannot read Bale settings, campaigns or reports; foreign contact ids refused' => function () use ($setup, $h) {
        $setup();
        if ($h->a->otherOrgId === null) {
            throw new LiteSkip('only one organization in crm_local');
        }
        $other = $h->a->otherOrgId;
        $mine = $h->groupCampaign;
        OrganizationContext::runAs($other, function () use ($h, $mine) {
            $view = $h->settings->view();
            LiteAssert::same('disconnected', $view['status']);
            LiteAssert::same(null, $view['key_hint']);
            LiteAssert::same(null, $h->settings->credentials());
            LiteAssert::same(0, (new MessageRepository())->countsForCampaign($mine)['total']);
            LiteAssert::throws(NotFoundException::class, fn () => $h->campaigns->find($h->a->admin, $mine));
        });
        // A row of the other organization is never read for ours.
        $h->a->pdo->prepare("INSERT INTO organization_bale_settings (organization_id, bot_id, api_key_encrypted, api_key_hint, status) VALUES (?, 99, ?, 'OTHR', 'configured')")->execute([$other, SecretBox::encrypt('other-org-KEY-OTHR')]);
        LiteAssert::same('7781', $h->settings->view()['key_hint']);
        LiteAssert::same($h->key, $h->settings->credentials()['api_key'] ?? null);

        if ($h->otherContact !== null) {
            $h->a->as($h->a->admin);
            $r = $h->a->request('POST', '/marketing/campaigns', [], ['name' => 'x', 'channel' => 'bale', 'message_class' => 'marketing', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->otherContact]]);
            LiteAssert::same(422, $r['status'], 'foreign contact refused');
        }
    },

    'contact page: "Send Bale message" shortcut opens a one-contact Bale campaign draft, only when the channel is available' => function () use ($setup, $h, $entitle) {
        $setup();
        $h->a->as($h->a->admin);
        $url = '/marketing/campaigns/create?channel=bale&amp;contact=' . $h->c[1];
        LiteAssert::contains($url, $h->a->request('GET', '/contacts/' . $h->c[1])['body']);
        $form = $h->a->request('GET', '/marketing/campaigns/create', ['channel' => 'bale', 'contact' => (string) $h->c[1]])['body'];
        LiteAssert::contains('maxlength="4000"', $form);
        LiteAssert::contains('data-selected="' . $h->c[1] . '"', $form);
        $entitle('bale_messaging', false);
        LiteAssert::notContains($url, $h->a->request('GET', '/contacts/' . $h->c[1])['body']);
        $entitle('bale_messaging', true);
    },

    'content: text over the 4,000-character application limit refused; {{unsubscribe_url}} refused (no links for Bale)' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $base = ['name' => 'x', 'channel' => 'bale', 'message_class' => 'marketing', 'audience_type' => 'group', 'group_id' => (string) $h->groupId];
        LiteAssert::same(422, $h->a->request('POST', '/marketing/campaigns', [], $base + ['body_text' => str_repeat('ب', 4001)])['status']);
        LiteAssert::same(302, $h->a->request('POST', '/marketing/campaigns', [], $base + ['body_text' => str_repeat('ب', 4000)])['status']);
        LiteAssert::same(422, $h->a->request('POST', '/marketing/campaigns', [], $base + ['body_text' => 'x {{unsubscribe_url}}'])['status']);
    },

    'billing seam: a failing sink never changes the outcome; nothing touches Wallet, ledger, notification credit or Finance tables' => function () use ($setup, $h, $contactsCampaign, $approve, $run) {
        $setup();
        $h->sink->throw = true;
        $id = $contactsCampaign([14], 'LiteBale sink');
        $approve($id);
        $run($id);
        LiteAssert::same('sent', (string) $h->a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
        $h->sink->throw = false;
        foreach ($h->financeTables as $table) {
            LiteAssert::same($h->financeBefore[$table], (int) $h->a->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "{$table} unchanged");
        }
        $source = '';
        foreach (glob(dirname(__DIR__, 2) . '/modules/MarketingBale/{,*/}*.php', GLOB_BRACE) as $file) {
            $source .= file_get_contents($file);
        }
        foreach (['WalletService', 'wallet_ledger', '->debit(', '->credit(', 'tryCharge'] as $needle) {
            LiteAssert::notContains($needle, $source, "MarketingBale never uses {$needle}");
        }
    },

    'secrets: the key never appears in pages, JSON ticks, message rows, campaign errors, timeline or today\'s log' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $tick = $h->a->request('POST', '/marketing/campaigns/' . $h->groupCampaign . '/process')['body'];
        LiteAssert::notContains($h->key, $tick);
        LiteAssert::notContains($h->key, $h->a->request('GET', '/marketing/campaigns/' . $h->groupCampaign)['body']);
        LiteAssert::notContains($h->key, $h->a->request('GET', '/org/messaging')['body']);
        $like = '%' . $h->key . '%';
        foreach (['SELECT COUNT(*) FROM marketing_messages WHERE error LIKE ?', 'SELECT COUNT(*) FROM marketing_campaigns WHERE last_error LIKE ?', 'SELECT COUNT(*) FROM timeline_events WHERE description LIKE ?', 'SELECT COUNT(*) FROM organization_bale_settings WHERE last_error LIKE ? OR api_key_hint LIKE ?'] as $sql) {
            $stmt = $h->a->pdo->prepare($sql);
            $stmt->execute(array_fill(0, substr_count($sql, '?'), $like));
            LiteAssert::same(0, (int) $stmt->fetchColumn(), $sql);
        }
        foreach (glob(dirname(__DIR__, 2) . '/storage/logs/*' . gmdate('Y-m-d') . '*.log') ?: [] as $log) {
            LiteAssert::notContains($h->key, (string) file_get_contents($log), basename($log));
        }
    },

    'disconnect: key and bot id removed, sending blocked, history kept' => function () use ($setup, $h) {
        $setup();
        $messages = (int) $h->a->pdo->query('SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = ' . $h->groupCampaign)->fetchColumn();
        $h->a->as($h->a->admin);
        $h->a->request('POST', '/org/messaging/bale/disconnect');
        $row = $h->a->pdo->query("SELECT * FROM organization_bale_settings WHERE organization_id = {$h->a->orgId}")->fetch();
        LiteAssert::same(null, $row['api_key_encrypted']);
        LiteAssert::same(null, $row['bot_id']);
        LiteAssert::same('disconnected', $row['status']);
        LiteAssert::same('bale.error.not_configured', $h->settings->unavailableReason());
        LiteAssert::same($messages, (int) $h->a->pdo->query('SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = ' . $h->groupCampaign)->fetchColumn());
    },

    'help guide: Persian-only article seeded (MarketingBale 0002), linked from the Bale card in Persian only, hidden in other languages' => function () use ($setup, $h) {
        $setup();
        $repo = new \App\Infrastructure\Repositories\HelpArticleRepository();
        $fa = $repo->findBySlug('bale-safir-setup', 'fa');
        if ($fa === null) {
            throw new LiteSkip('help article not seeded — run the MarketingBale migrations');
        }
        LiteAssert::same(null, $repo->findBySlug('bale-safir-setup', 'en'), 'no English version, no fallback');
        $file = (string) file_get_contents(dirname(__DIR__, 2) . '/database/help-content/bale-safir-setup/fa.html');
        $clean = \App\Infrastructure\Security\HtmlSanitizer::clean($file);
        foreach (['business.bale.ai', 'ارسال پیام', 'وب‌سرویس', 'افزایش اعتبار', 'توکن وب‌سرویس (API)', 'شناسهٔ بازو (Bot_id)', '@botfather', '<table>'] as $needle) {
            LiteAssert::contains($needle, $clean, 'kept after sanitizing: ' . $needle);
        }
        LiteAssert::false((bool) preg_match('/\b[A-Za-z0-9]{16}\b/', strip_tags($file)), 'no token-like string in the guide');

        $h->a->as($h->a->admin);
        $h->a->locale('fa');
        LiteAssert::contains('href="/help/bale-safir-setup"', $h->a->request('GET', '/org/messaging')['body']);
        $page = $h->a->request('GET', '/help/bale-safir-setup');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('business.bale.ai', $page['body']);
        $h->a->locale('en');
        LiteAssert::notContains('/help/bale-safir-setup', $h->a->request('GET', '/org/messaging')['body'], 'no Persian-only link in English');
    },

    'translations: every bale.* key and every new marketing key exists in all five languages' => function () {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'bale.') || str_contains($k, 'bale') || in_array($k, ['marketing.unsupported', 'marketing.unknown_outcome', 'marketing.confirm_consent', 'marketing.retry_unknown', 'marketing.channel_text'], true));
        LiteAssert::true(count($keys) >= 45);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $other = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                LiteAssert::true(isset($other[$key]) && trim((string) $other[$key]) !== '', "{$lang}: {$key}");
            }
        }
        foreach (['safir_3_rate_limited', 'http_rate_limited', 'safir_20_payment_required', 'safir_21_contact_limit', 'not_configured'] as $reason) {
            LiteAssert::true(isset($en['bale.pause.' . $reason]), $reason);
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        if (isset($h->a)) {
            $h->a->rollBack();
        }
        LiteAssert::true(true);
    },
];
