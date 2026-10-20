<?php

declare(strict_types=1);

/**
 * Phase B — delivery reports for transactional messages (SMS status
 * polling, Brevo webhook events) and the Marketing → Transactional page.
 * crm_local only, one transaction, rolled back. SMS goes to
 * FakeSmsSender; nothing is sent, no provider is called.
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Application\Services\TimelineService;
use App\Infrastructure\Messaging\DeliveryReceipt;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Messaging\TransactionalMessage;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\Services\Entitlements;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\WebhookService;
use Modules\Marketing\SuppressionRepository;
use Modules\Marketing\TransactionalMessageRepository;
use Modules\Marketing\WebhookEventRepository;
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
        $h->a->pdo->query('SELECT delivery_status FROM marketing_transactional_messages LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Marketing 0014 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email')");
    foreach (['marketing', 'marketing_email'] as $key) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    }
    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);
    $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, 'Arash', '09136660001', 'active')")->execute([$a->orgId]);
    $h->contactId = (int) $pdo->lastInsertId();

    $h->delivery = $c->make(TransactionalDeliveryInterface::class);
    $h->worker = $c->make(QueueWorker::class);
    $h->repo = new TransactionalMessageRepository();
    $h->ready = true;
};

$send = fn (string $reference, string $phone) => $h->delivery->deliver(new TransactionalMessage(
    $h->a->orgId, 'sms', $phone, 'Arash', 'Your opinion', 'How was it? https://crm.example.test/p/x', 'cx_survey', $reference, $h->contactId, 'en',
));

$row = fn (string $reference) => (function () use ($h, $reference): array {
    $stmt = $h->a->pdo->prepare('SELECT * FROM marketing_transactional_messages WHERE organization_id = ? AND reference = ?');
    $stmt->execute([$h->a->orgId, $reference]);

    return $stmt->fetch();
})();

/** A WebhookService wired like MarketingModule's (the module keeps its own instance private). */
$webhooks = fn () => new WebhookService(
    $h->a->container()->make(EmailProviderService::class), new MessageRepository(), new SuppressionRepository(), new WebhookEventRepository(),
    new Entitlements(new ModuleRepository(), new OrganizationModuleRepository()), $h->a->container()->make(TimelineService::class), new TransactionalMessageRepository(),
);
$applyEvent = fn (array $event) => OrganizationContext::runAs($h->a->orgId, fn () => (new \ReflectionMethod(WebhookService::class, 'apply'))->invoke($webhooks(), 'brevo', $event + ['email' => 'arash@example.test', 'occurred_at' => date('Y-m-d H:i:s'), 'reason' => '']));

return [
    'accepted is not delivered: a sent SMS shows no delivery state until the provider reports one' => function () use ($setup, $h, $send, $row) {
        $setup();
        LiteAssert::same(DeliveryReceipt::ACCEPTED, $send('lite_tr:1', '09136660001')->status);
        $r = $row('lite_tr:1');
        LiteAssert::same('sent', $r['status']);
        LiteAssert::same(null, $r['delivery_status']);
        LiteAssert::same('fakesms-own', $r['provider'], 'the sending account, as SMS logs name it');
    },

    'SMS delivery report: polling stores "delivered" with its time; a second poll changes nothing' => function () use ($setup, $h, $row) {
        $setup();
        $r = $row('lite_tr:1');
        $h->sms->statuses[(string) $r['provider_message_id']] = ['state' => 'delivered', 'code' => 10, 'text' => 'delivered'];
        $changed = OrganizationContext::runAs($h->a->orgId, fn () => $h->worker->pollDeliveryReports());
        LiteAssert::true($changed >= 1);
        $r = $row('lite_tr:1');
        LiteAssert::same('delivered', $r['delivery_status']);
        LiteAssert::true($r['delivered_at'] !== null && $r['status_checked_at'] !== null);
        LiteAssert::same(0, OrganizationContext::runAs($h->a->orgId, fn () => $h->worker->pollDeliveryReports()), 'final state is not polled again');
    },

    'SMS "blocked" report: stored and the number added to the SMS opt-out list; "unknown" stores nothing' => function () use ($setup, $h, $send, $row) {
        $setup();
        $send('lite_tr:2', '09136660002');
        $send('lite_tr:3', '09136660003');
        $h->sms->statuses[(string) $row('lite_tr:2')['provider_message_id']] = ['state' => 'blocked', 'code' => 11, 'text' => 'blocked'];
        $h->sms->statuses[(string) $row('lite_tr:3')['provider_message_id']] = ['state' => 'unknown', 'code' => 0, 'text' => ''];
        OrganizationContext::runAs($h->a->orgId, fn () => $h->worker->pollDeliveryReports());
        LiteAssert::same('blocked', $row('lite_tr:2')['delivery_status']);
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_suppressions WHERE organization_id = {$h->a->orgId} AND channel = 'sms' AND address = '09136660002' AND reason = 'blocked'")->fetchColumn());
        LiteAssert::same(null, $row('lite_tr:3')['delivery_status']);
    },

    'cron entry point covers organizations that only sent transactional SMS' => function () use ($setup, $h, $row) {
        $setup();
        $h->sms->statuses[(string) $row('lite_tr:3')['provider_message_id']] = ['state' => 'undelivered', 'code' => 6, 'text' => 'failed'];
        $h->a->pdo->exec("UPDATE marketing_transactional_messages SET status_checked_at = NULL WHERE reference = 'lite_tr:3'");
        $h->worker->pollAllDeliveryReports(microtime(true) + 10);
        LiteAssert::same('undelivered', $row('lite_tr:3')['delivery_status']);
    },

    'email webhook: Brevo events update a transactional email; replays ignored; no downgrade; hard bounce → opt-out' => function () use ($setup, $h, $row, $applyEvent) {
        $setup();
        $h->a->pdo->prepare("INSERT INTO marketing_transactional_messages (organization_id, reference, purpose, channel, address, status, provider, provider_message_id, sent_at)
            VALUES (?, 'lite_tr:mail', 'cx_survey', 'email', 'arash@example.test', 'sent', 'brevo', '<lite-tr-1@smtp-relay.brevo.com>', ?)")->execute([$h->a->orgId, date('Y-m-d H:i:s')]);
        LiteAssert::true($applyEvent(['key' => 'lite-tr-ev-1', 'type' => 'delivered', 'message_id' => '<lite-tr-1@smtp-relay.brevo.com>']));
        LiteAssert::same('delivered', $row('lite_tr:mail')['delivery_status']);
        LiteAssert::false($applyEvent(['key' => 'lite-tr-ev-1', 'type' => 'delivered', 'message_id' => '<lite-tr-1@smtp-relay.brevo.com>']), 'replay');
        $applyEvent(['key' => 'lite-tr-ev-2', 'type' => 'deferred', 'message_id' => '<lite-tr-1@smtp-relay.brevo.com>']);
        LiteAssert::same('delivered', $row('lite_tr:mail')['delivery_status'], 'never downgraded');
        $applyEvent(['key' => 'lite-tr-ev-3', 'type' => 'hard_bounce', 'message_id' => '<lite-tr-1@smtp-relay.brevo.com>', 'reason' => 'mailbox does not exist']);
        LiteAssert::same('bounced', $row('lite_tr:mail')['delivery_status']);
        LiteAssert::same(1, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_suppressions WHERE organization_id = {$h->a->orgId} AND channel = 'email' AND address = 'arash@example.test'")->fetchColumn());
    },

    'email webhook: a message id of another organization is never touched' => function () use ($setup, $h, $applyEvent) {
        $setup();
        if ($h->a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $h->a->pdo->prepare("INSERT INTO marketing_transactional_messages (organization_id, reference, purpose, channel, address, status, provider, provider_message_id, sent_at)
            VALUES (?, 'lite_tr:foreign', 'cx_survey', 'email', 'x@example.test', 'sent', 'brevo', '<lite-tr-foreign@brevo>', NOW())")->execute([$h->a->otherOrgId]);
        LiteAssert::false($applyEvent(['key' => 'lite-tr-ev-9', 'type' => 'delivered', 'message_id' => '<lite-tr-foreign@brevo>']));
        LiteAssert::same(null, $h->a->pdo->query("SELECT delivery_status FROM marketing_transactional_messages WHERE reference = 'lite_tr:foreign'")->fetchColumn());
    },

    'Transactional page: lists source, channel, status and delivery; filters work; renders in fa without raw keys' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $res = $h->a->request('GET', '/marketing/transactional');
        LiteAssert::same(200, $res['status']);
        foreach (['Customer satisfaction survey', 'Accepted by provider', 'Delivered', 'Bounced', '09136660001', 'Arash'] as $text) {
            LiteAssert::contains($text, $res['body']);
        }
        LiteAssert::notContains('lite_tr:foreign', $res['body']);
        LiteAssert::notContains('x@example.test', $res['body'], 'other organization never listed');
        $filtered = $h->a->request('GET', '/marketing/transactional', ['channel' => 'email'])['body'];
        LiteAssert::contains('arash@example.test', $filtered);
        LiteAssert::notContains('09136660001', $filtered);
        $h->a->locale('fa');
        LiteAssert::notContains('marketing.transactional.', $h->a->request('GET', '/marketing/transactional')['body']);
        $h->a->locale('en');
        LiteAssert::contains('/marketing/transactional', $h->a->request('GET', '/marketing/history')['body'], 'tab in the Marketing navigation');
    },

    'Transactional page: needs marketing.view; addresses masked without contacts.view' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->noRole);
        LiteAssert::same('forbidden', $h->a->outcome('GET', '/marketing/transactional'));
        if ($h->a->member === null) {
            return;
        }
        $pdo = $h->a->pdo;
        $pdo->prepare("INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, 'lite_tr_viewer', 'lite_tr_viewer', 0)")->execute([$h->a->orgId]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, 'marketing.view', 'organization')")->execute([$roleId]);
        $h->a->as(array_merge($h->a->member, ['role_id' => $roleId]));
        $body = $h->a->request('GET', '/marketing/transactional')['body'];
        LiteAssert::notContains('09136660001', $body);
        LiteAssert::contains('0913', $body);
        LiteAssert::notContains('/contacts/' . $h->contactId, $body);
        $h->a->as($h->a->admin);
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_transactional_messages WHERE reference LIKE 'lite_tr:%'")->fetchColumn());
    },
];
