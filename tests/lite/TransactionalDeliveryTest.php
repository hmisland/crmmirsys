<?php

declare(strict_types=1);

/**
 * Phase 2 — Marketing implements Core's TransactionalDeliveryInterface
 * (docs/integrations-architecture.md, docs/marketing-module.md).
 * crm_local only, one transaction, rolled back. SMS goes to
 * FakeSmsSender; no real provider is called, nothing is sent.
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\SmsService;
use App\Infrastructure\Messaging\DeliveryReceipt;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Messaging\TransactionalMessage;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use Modules\CustomerSatisfaction\Services\InvitationService;
use Modules\Marketing\Services\TransactionalDelivery;
use Tests\Lite\Fixtures\FakeSmsSender;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Fixtures/MarketingFakes.php';

$h = new \stdClass();
$h->ready = false;
$h->reason = '';

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT 1 FROM marketing_transactional_messages LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Marketing 0013 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();

    // Entitle the organization (inside the rolled-back transaction); Bale stays off.
    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email', 'bale_messaging', 'customer_satisfaction')");
    foreach (['marketing', 'marketing_email', 'customer_satisfaction'] as $key) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $h->moduleIds[$key] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $h->moduleIds[$key]]);
    }
    $pdo->exec('DELETE FROM organization_email_providers WHERE organization_id = ' . $a->orgId);

    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);

    $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, email, status) VALUES (?, 'Nima', 'Lite', '09135550001', 'nima@example.test', 'active')")->execute([$a->orgId]);
    $h->contactId = (int) $pdo->lastInsertId();

    $h->delivery = $c->make(TransactionalDeliveryInterface::class);
    // The composer is not a container service: take the deliverer's own (same installation dedupe prefix).
    $h->composer = (new \ReflectionProperty($h->delivery, 'composer'))->getValue($h->delivery);
    $h->ready = true;
};

$message = fn (string $reference, string $address = '09135550001', string $channel = 'sms', ?int $contactId = null, string $text = 'How was your order? https://crm.example.test/p/abc') => new TransactionalMessage(
    $h->a->orgId, $channel, $address, 'Nima', 'Your opinion', $text, 'cx_survey', $reference, $contactId, 'en',
);

$row = fn (string $reference) => (function () use ($h, $reference): ?array {
    $stmt = $h->a->pdo->prepare('SELECT * FROM marketing_transactional_messages WHERE organization_id = ? AND reference = ?');
    $stmt->execute([$h->a->orgId, $reference]);

    return $stmt->fetch() ?: null;
})();

return [
    'Marketing binds Core TransactionalDeliveryInterface' => function () use ($setup, $h) {
        $setup();
        LiteAssert::true($h->delivery instanceof TransactionalDelivery);
    },

    'available channels: SMS ready; email without a connected provider is not offered; nothing without the marketing module' => function () use ($setup, $h) {
        $setup();
        LiteAssert::same(['sms'], $h->delivery->availableChannels($h->a->orgId));

        $h->a->pdo->exec('UPDATE organization_modules SET enabled = 0 WHERE organization_id = ' . $h->a->orgId . ' AND module_id = ' . $h->moduleIds['marketing']);
        LiteAssert::same([], $h->delivery->availableChannels($h->a->orgId));
        $h->a->pdo->exec('UPDATE organization_modules SET enabled = 1 WHERE organization_id = ' . $h->a->orgId . ' AND module_id = ' . $h->moduleIds['marketing']);
        LiteAssert::same(['sms'], $h->delivery->availableChannels($h->a->orgId));
    },

    'SMS: sent once, history row, contact timeline, SMS log labelled, provider dedupe id outside campaign rows' => function () use ($setup, $h, $message, $row) {
        $setup();
        $receipt = $h->delivery->deliver($message('lite_td:1', '+98 913 555 0001', 'sms', $h->contactId));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $receipt->status);
        LiteAssert::same(1, count($h->sms->sent));
        LiteAssert::same('How was your order? https://crm.example.test/p/abc', $h->sms->sent[0]['message']);

        $r = $row('lite_td:1');
        LiteAssert::same('sent', $r['status']);
        LiteAssert::same('09135550001', $r['address']);
        LiteAssert::same($h->contactId, (int) $r['contact_id']);
        LiteAssert::same($receipt->providerReference, $r['provider_message_id']);
        LiteAssert::same('cx_survey', $r['purpose']);
        LiteAssert::same($h->composer->dedupeId(900_000_000_000 + (int) $r['id']), $h->sms->sent[0]['local_id']);

        $timeline = $h->a->pdo->query("SELECT COUNT(*) FROM timeline_events WHERE subject_type = 'contact' AND subject_id = {$h->contactId} AND type = 'marketing.transactional_sent'")->fetchColumn();
        LiteAssert::same(1, (int) $timeline);
        $log = $h->a->pdo->query("SELECT context FROM sms_logs ORDER BY id DESC LIMIT 1")->fetchColumn();
        LiteAssert::same('marketing.transactional:' . $r['id'], $log);
    },

    'idempotent: the same reference again sends nothing and returns the same receipt' => function () use ($setup, $h, $message) {
        $setup();
        $first = $h->delivery->deliver($message('lite_td:1'));
        $again = $h->delivery->deliver($message('lite_td:1', '09135550009'));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $again->status);
        LiteAssert::same($first->providerReference, $again->providerReference);
        LiteAssert::same(1, count($h->sms->sent));
        LiteAssert::same(1, $h->sms->sendCalls);
    },

    'opt-out on the channel list blocks the message (any reason), recorded as skipped' => function () use ($setup, $h, $message, $row) {
        $setup();
        $h->a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'sms', '09135550002', 'unsubscribed', 'link')")->execute([$h->a->orgId]);
        $receipt = $h->delivery->deliver($message('lite_td:2', '09135550002'));
        LiteAssert::same(DeliveryReceipt::FAILED, $receipt->status);
        LiteAssert::same('marketing.transactional.error.suppressed', $receipt->errorKey);
        LiteAssert::same(1, count($h->sms->sent));
        LiteAssert::same('skipped', $row('lite_td:2')['status']);
        LiteAssert::same('suppressed_unsubscribed', $row('lite_td:2')['skip_reason']);
    },

    'network failure: retry receipt, row reusable; the next call sends exactly once' => function () use ($setup, $h, $message, $row) {
        $setup();
        $h->sms->script[] = SmsResult::failure('Could not reach the SMS provider.');
        $receipt = $h->delivery->deliver($message('lite_td:3', '09135550003'));
        LiteAssert::same(DeliveryReceipt::RETRY, $receipt->status);
        LiteAssert::same('marketing.transactional.error.provider_retry', $receipt->errorKey);
        LiteAssert::same('retry', $row('lite_td:3')['status']);
        LiteAssert::same(1, count($h->sms->sent));

        $receipt = $h->delivery->deliver($message('lite_td:3', '09135550003'));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $receipt->status);
        LiteAssert::same(2, count($h->sms->sent));
        LiteAssert::same('sent', $row('lite_td:3')['status']);
        LiteAssert::same(2, (int) $row('lite_td:3')['attempts']);
    },

    'provider rejection is final: failed, and a repeated call does not send' => function () use ($setup, $h, $message, $row) {
        $setup();
        $h->sms->script[] = new SmsResult(false, 'Invalid line', 412, []);
        $receipt = $h->delivery->deliver($message('lite_td:4', '09135550004'));
        LiteAssert::same(DeliveryReceipt::FAILED, $receipt->status);
        LiteAssert::same('marketing.transactional.error.rejected', $receipt->errorKey);
        $calls = $h->sms->sendCalls;
        $again = $h->delivery->deliver($message('lite_td:4', '09135550004'));
        LiteAssert::same(DeliveryReceipt::FAILED, $again->status);
        LiteAssert::same($calls, $h->sms->sendCalls);
        LiteAssert::same('failed', $row('lite_td:4')['status']);
    },

    'claimed by a live run: retry later, nothing sent' => function () use ($setup, $h, $message) {
        $setup();
        $h->a->pdo->prepare("INSERT INTO marketing_transactional_messages (organization_id, reference, purpose, channel, address, status, attempts, lock_token, locked_until) VALUES (?, 'lite_td:5', 'cx_survey', 'sms', '09135550005', 'processing', 1, 'tok', ?)")
            ->execute([$h->a->orgId, date('Y-m-d H:i:s', time() + 120)]);
        $calls = $h->sms->sendCalls;
        $receipt = $h->delivery->deliver($message('lite_td:5', '09135550005'));
        LiteAssert::same(DeliveryReceipt::RETRY, $receipt->status);
        LiteAssert::same('marketing.transactional.error.in_progress', $receipt->errorKey);
        LiteAssert::same($calls, $h->sms->sendCalls);
    },

    'SMS left in processing by a dead run: found at the provider → sent, no resend; not found → sent once' => function () use ($setup, $h, $message, $row) {
        $setup();
        $stale = date('Y-m-d H:i:s', time() - 600);
        $insert = $h->a->pdo->prepare("INSERT INTO marketing_transactional_messages (organization_id, reference, purpose, channel, address, status, attempts, lock_token, locked_until) VALUES (?, ?, 'cx_survey', 'sms', ?, 'processing', 1, 'tok', ?)");
        $insert->execute([$h->a->orgId, 'lite_td:6', '09135550006', $stale]);
        $id6 = (int) $h->a->pdo->lastInsertId();
        $h->sms->seenLocalIds[$h->composer->dedupeId(900_000_000_000 + $id6)] = 7777;
        $sent = count($h->sms->sent);
        $receipt = $h->delivery->deliver($message('lite_td:6', '09135550006'));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $receipt->status);
        LiteAssert::same('7777', $receipt->providerReference);
        LiteAssert::same($sent, count($h->sms->sent));
        LiteAssert::same('sent', $row('lite_td:6')['status']);

        $insert->execute([$h->a->orgId, 'lite_td:7', '09135550007', $stale]);
        $receipt = $h->delivery->deliver($message('lite_td:7', '09135550007'));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $receipt->status);
        LiteAssert::same($sent + 1, count($h->sms->sent));
    },

    'email left in processing by a dead run: unknown, final, never resent' => function () use ($setup, $h, $message, $row) {
        $setup();
        $h->a->pdo->prepare("INSERT INTO marketing_transactional_messages (organization_id, reference, purpose, channel, address, status, attempts, lock_token, locked_until) VALUES (?, 'lite_td:8', 'cx_survey', 'email', 'nima@example.test', 'processing', 1, 'tok', ?)")
            ->execute([$h->a->orgId, date('Y-m-d H:i:s', time() - 600)]);
        $receipt = $h->delivery->deliver($message('lite_td:8', 'nima@example.test', 'email'));
        LiteAssert::same(DeliveryReceipt::FAILED, $receipt->status);
        LiteAssert::same('marketing.transactional.error.unknown', $receipt->errorKey);
        LiteAssert::same('1', (string) $row('lite_td:8')['ambiguous']);
        $again = $h->delivery->deliver($message('lite_td:8', 'nima@example.test', 'email'));
        LiteAssert::same('marketing.transactional.error.unknown', $again->errorKey);
    },

    'invalid input: bad address, empty text, unknown channel, empty reference → failed, nothing recorded or sent' => function () use ($setup, $h, $message, $row) {
        $setup();
        $calls = $h->sms->sendCalls;
        LiteAssert::same('marketing.transactional.error.invalid_address', $h->delivery->deliver($message('lite_td:9', '12'))->errorKey);
        LiteAssert::same('marketing.error.body_required', $h->delivery->deliver($message('lite_td:9', '09135550009', 'sms', null, '  '))->errorKey);
        LiteAssert::same('marketing.error.invalid_channel', $h->delivery->deliver($message('lite_td:9', '09135550009', 'fax'))->errorKey);
        LiteAssert::same('marketing.transactional.error.invalid_request', $h->delivery->deliver($message(' '))->errorKey);
        LiteAssert::same(null, $row('lite_td:9'));
        LiteAssert::same($calls, $h->sms->sendCalls);
    },

    'channel not ready (email without provider): retry, nothing recorded' => function () use ($setup, $h, $message, $row) {
        $setup();
        $receipt = $h->delivery->deliver($message('lite_td:10', 'nima@example.test', 'email'));
        LiteAssert::same(DeliveryReceipt::RETRY, $receipt->status);
        LiteAssert::same('marketing.error.email_not_configured', $receipt->errorKey);
        LiteAssert::same(null, $row('lite_td:10'));
    },

    'tenant safety: a contact id of another organization is not stored; references are per organization' => function () use ($setup, $h, $message, $row) {
        $setup();
        if ($h->a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $h->a->pdo->prepare("INSERT INTO contacts (organization_id, first_name, status) VALUES (?, 'Foreign', 'active')")->execute([$h->a->otherOrgId]);
        $foreign = (int) $h->a->pdo->lastInsertId();
        $receipt = $h->delivery->deliver($message('lite_td:11', '09135550011', 'sms', $foreign));
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $receipt->status);
        LiteAssert::same(null, $row('lite_td:11')['contact_id']);

        // The other organization has no SMS account and no Marketing module here: same reference, its own answer.
        $other = new TransactionalMessage($h->a->otherOrgId, 'sms', '09135550011', '', '', 'x', 'cx_survey', 'lite_td:11');
        $h->a->pdo->exec('DELETE FROM organization_modules WHERE organization_id = ' . $h->a->otherOrgId . ' AND module_id = ' . $h->moduleIds['marketing']);
        LiteAssert::same(DeliveryReceipt::RETRY, $h->delivery->deliver($other)->status);
        LiteAssert::same([], $h->delivery->availableChannels($h->a->otherOrgId));
        LiteAssert::same($h->a->orgId, OrganizationContext::id(), 'context restored');
    },

    'end to end: a due Customer Satisfaction invitation goes out through Marketing SMS with its single-use link' => function () use ($setup, $h) {
        $setup();
        $pdo = $h->a->pdo;
        $c = $h->a->container();
        $c->make(OrganizationEntitlements::class)->reset();
        $pdo->prepare("INSERT INTO cx_surveys (organization_id, name, type, status, trigger_event, send_delay_minutes, link_valid_days, channel) VALUES (?, 'Lite TD survey', 'csat', 'active', 'order.completed', 0, 7, 'sms')")->execute([$h->a->orgId]);
        $surveyId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cx_invitations (organization_id, survey_id, source, context_type, context_ref, context_label, contact_id, recipient_name, recipient_phone, status, send_after)
            VALUES (?, ?, 'lite', 'order', 'lite-td-1', '#1001', ?, 'Nima', '09135550012', 'scheduled', '2000-01-01 00:00:00')")->execute([$h->a->orgId, $surveyId, $h->contactId]);
        $invitationId = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE cx_invitations SET send_after = '2999-01-01 00:00:00' WHERE id <> {$invitationId} AND status IN ('scheduled', 'waiting_channel')");

        $sent = count($h->sms->sent);
        $stats = $c->make(InvitationService::class)->dispatchDue(10);
        LiteAssert::same(1, $stats['sent']);
        LiteAssert::same($sent + 1, count($h->sms->sent));
        $sms = $h->sms->sent[$sent];
        LiteAssert::same('09135550012', $sms['receptor']);
        LiteAssert::same(1, preg_match('#/p/[A-Za-z0-9_-]{43}#', $sms['message']));

        $inv = $pdo->query("SELECT status, channel, delivery_ref FROM cx_invitations WHERE id = {$invitationId}")->fetch();
        LiteAssert::same('sent', $inv['status']);
        LiteAssert::same('sms', $inv['channel']);
        $r = $pdo->query("SELECT * FROM marketing_transactional_messages WHERE reference = 'cx_invitation:{$invitationId}'")->fetch();
        LiteAssert::same('sent', $r['status']);
        LiteAssert::same($inv['delivery_ref'], $r['provider_message_id']);

        // A second run sends nothing more.
        $c->make(InvitationService::class)->dispatchDue(10);
        LiteAssert::same($sent + 1, count($h->sms->sent));
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->pdo->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_transactional_messages WHERE reference LIKE 'lite_td:%'")->fetchColumn());
    },
];
