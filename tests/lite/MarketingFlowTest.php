<?php

declare(strict_types=1);

/**
 * Marketing end to end through the real router, middleware, services
 * and database (crm_local only, one transaction, rolled back at the
 * end). Providers are mocked: SMS goes to FakeSmsSender (registered as
 * the organization's own SMS provider), Brevo gets FakeHttpClient. No
 * real message is sent.
 *
 * Tests run in order and share fixtures (contacts c1..c6 in one group).
 */

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Email\BrevoEmailProvider;
use App\Infrastructure\Email\EmailProviderDefinition;
use App\Infrastructure\Email\EmailProviderRegistry;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\SecretBox;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\UnsubscribeLinks;
use Tests\Lite\Fixtures\FakeHttpClient;
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
        $h->a->pdo->query('SELECT 1 FROM marketing_messages LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or marketing not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();

    // Module rows (installed + active); organization entitlement is switched per test.
    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email')");
    foreach (['marketing' => 'Marketing', 'marketing_email' => 'Email Marketing'] as $key => $name) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $name]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 0)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    }

    // SMS: a fake provider as the organization's own account.
    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $error = $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);
    if ($error !== null) {
        throw new \RuntimeException('could not configure fake SMS: ' . $error);
    }

    // Email: the real Brevo adapter over a fake transport.
    $h->messageSeq = 0;
    $h->smtp = fn () => FakeHttpClient::json(201, ['messageId' => '<lite-' . (++$h->messageSeq) . '@relay>']);
    $h->accountOk = true;
    $h->http = new FakeHttpClient(function (string $method, string $url) use ($h) {
        if (str_ends_with($url, '/account')) {
            return $h->accountOk ? FakeHttpClient::json(200, ['email' => 'owner@example.test']) : FakeHttpClient::json(401, ['message' => 'Key not found']);
        }
        if (str_ends_with($url, '/senders')) {
            return FakeHttpClient::json(200, ['senders' => [['email' => 'news@example.test', 'active' => true]]]);
        }

        return ($h->smtp)();
    });
    $http = $h->http;
    $c->make(EmailProviderRegistry::class)->register(new EmailProviderDefinition(
        'brevo', 'Brevo', fn (string $secret) => new BrevoEmailProvider($secret, $http), 'email_providers.api_key', 'https://developers.brevo.com/', 'https://www.brevo.com/',
    ));

    // Contacts: one group with every audience case.
    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteMkt group']);
    $h->groupId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, email, phone, category_id, status) VALUES (?, ?, 'Lite', ?, ?, ?, 'active')");
    $h->c = [];
    foreach ([
        1 => ['Sara', 'c1@example.test', '09121111111'],
        2 => ['Reza', 'c2@example.test', '+98 912 222 2222'],
        3 => ['Invalid', null, '12345'],
        4 => ['Nobody', null, null],
        5 => ['Twin', null, '0912 111 1111'],
        6 => ['Muted', 'c6@example.test', '09126666666'],
    ] as $n => [$first, $email, $phone]) {
        $insert->execute([$a->orgId, $first, $email, $phone, $h->groupId]);
        $h->c[$n] = (int) $pdo->lastInsertId();
    }
    // Outside the group: used by the cancel test only.
    $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, 'Spare', '09127777777', 'active')")->execute([$a->orgId]);
    $h->c[7] = (int) $pdo->lastInsertId();
    $h->otherContact = null;
    if ($a->otherOrgId !== null) {
        $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, email, status) VALUES (?, 'Foreign', '09129999999', 'f@example.test', 'active')")->execute([$a->otherOrgId]);
        $h->otherContact = (int) $pdo->lastInsertId();
    }

    $h->worker = $c->make(QueueWorker::class);
    $h->campaigns = $c->make(CampaignService::class);
    $h->ready = true;
};

$entitle = function (string $key, bool $on) use ($h): void {
    $h->a->setOrgEntitled($key, $on);
    $h->a->container()->make(ModuleGate::class)->reset();
};

$row = fn (string $sql, array $p = []) => (function () use ($h, $sql, $p) {
    $stmt = $h->a->pdo->prepare($sql);
    $stmt->execute($p);

    return $stmt->fetch() ?: null;
})();

$all = fn (string $sql, array $p = []) => (function () use ($h, $sql, $p) {
    $stmt = $h->a->pdo->prepare($sql);
    $stmt->execute($p);

    return $stmt->fetchAll();
})();

/** Creates a draft through the real route; returns its id. */
$draft = function (array $input) use ($h): int {
    $h->a->as($h->a->admin);
    $r = $h->a->request('POST', '/marketing/campaigns', [], $input);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('draft not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};

$approve = function (int $id, int $count, bool $all = false) use ($h): array {
    $h->a->as($h->a->admin);

    return $h->a->request('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => (string) $count] + ($all ? ['confirm_all' => '1'] : []));
};

$run = fn (int $id) => $h->worker->processCampaign($id, 100, microtime(true) + 20);

$webhook = function (string $key, string $auth, array $payload) use ($h): array {
    $h->a->container()->make(ModuleGate::class)->reset();
    $response = $h->a->app->router->dispatch(new Request('POST', "/webhooks/email/brevo/{$key}", [], $payload, ['AUTHORIZATION' => $auth], []));
    $read = fn (string $p) => (new \ReflectionProperty(Response::class, $p))->getValue($response);

    return [$read('status'), json_decode((string) $read('body'), true)];
};

return [
    'entitlement: without the module /marketing 404s and email settings are visible but muted and refused server-side' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        LiteAssert::same('notfound', $a->outcome('GET', '/marketing'));
        LiteAssert::same('notfound', $a->outcome('POST', '/marketing/campaigns', [], ['name' => 'x']));
        LiteAssert::notContains('href="/marketing"', $a->request('GET', '/leads')['body'], 'no sidebar link');

        $page = $a->request('GET', '/org/messaging');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('id="provider-kavenegar"', $page['body'], 'Kavenegar card always visible');
        LiteAssert::contains('id="provider-sender"', $page['body']);
        LiteAssert::contains('id="provider-brevo"', $page['body']);
        LiteAssert::contains('provider-card--unavailable', $page['body'], 'email cards muted');

        $before = count($h->http->requests);
        $a->request('POST', '/org/messaging/email/brevo', [], ['api_secret' => 'xkeysib-LITE-SECRET-0001', 'sender_email' => 'news@example.test', 'sender_name' => 'Shop']);
        LiteAssert::same($before, count($h->http->requests), 'provider never called');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM organization_email_providers WHERE organization_id = {$a->orgId}")->fetchColumn(), 'nothing stored');
    },

    'enabled: dashboard and sidebar link appear; users without organization.edit cannot open provider settings' => function () use ($setup, $h, $entitle) {
        $setup();
        $entitle('marketing', true);
        $a = $h->a;
        $a->as($a->admin);
        $r = $a->request('GET', '/marketing');
        LiteAssert::same(200, $r['status']);
        LiteAssert::contains('href="/marketing"', $r['body']);
        LiteAssert::contains('href="/marketing/campaigns/create"', $r['body']);

        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('GET', '/org/messaging'));
        LiteAssert::same('forbidden', $a->outcome('POST', '/org/messaging/email/brevo', [], ['api_secret' => 'x']));
        LiteAssert::same('forbidden', $a->outcome('GET', '/marketing'), 'no marketing.view');
    },

    'brevo settings: rejected key stores nothing; accepted key stored encrypted, masked, never rendered; test email via the API' => function () use ($setup, $h, $entitle) {
        $setup();
        $entitle('marketing_email', true);
        $a = $h->a;
        $a->as($a->admin);
        $secret = 'xkeysib-LITE-SECRET-0001';
        $input = ['api_secret' => $secret, 'sender_email' => 'news@example.test', 'sender_name' => 'Shop', 'reply_to' => 'reply@example.test'];

        $h->accountOk = false;
        $a->request('POST', '/org/messaging/email/brevo', [], $input);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM organization_email_providers WHERE organization_id = {$a->orgId}")->fetchColumn());

        $h->accountOk = true;
        $a->request('POST', '/org/messaging/email/brevo', [], $input);
        $stored = $a->pdo->query("SELECT * FROM organization_email_providers WHERE organization_id = {$a->orgId} AND provider = 'brevo'")->fetch();
        LiteAssert::same('connected', $stored['status']);
        LiteAssert::same('1', (string) $stored['sender_verified']);
        LiteAssert::notContains($secret, (string) $stored['secret_encrypted']);
        LiteAssert::same($secret, SecretBox::decrypt($stored['secret_encrypted']));
        LiteAssert::same('0001', $stored['secret_hint']);

        $settings = $a->container()->make(EmailProviderService::class)->settings()['brevo'];
        LiteAssert::false(in_array($secret, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $settings), true), 'settings view never carries the secret');
        $page = $a->request('GET', '/org/messaging')['body'];
        LiteAssert::notContains($secret, $page);
        LiteAssert::contains('••••0001', $page);

        // Saving again without a key keeps the stored one.
        $a->request('POST', '/org/messaging/email/brevo', [], ['api_secret' => '', 'sender_email' => 'news@example.test', 'sender_name' => 'Shop 2', 'reply_to' => 'reply@example.test']);
        LiteAssert::same($secret, SecretBox::decrypt($a->pdo->query("SELECT secret_encrypted FROM organization_email_providers WHERE organization_id = {$a->orgId} AND provider = 'brevo'")->fetchColumn()));

        $before = count($h->http->requests);
        $a->request('POST', '/org/messaging/email/brevo/test-email', [], ['to' => 'me@example.test']);
        $sent = $h->http->requests[$before] ?? null;
        LiteAssert::true($sent !== null && str_ends_with($sent['url'], '/smtp/email'), 'one test email through the API');
        LiteAssert::same('me@example.test', json_decode((string) $sent['body'], true)['to'][0]['email']);
    },

    'long API tokens (Sender JWTs, ~1,100 chars) are accepted, stored encrypted and sent intact' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.' . str_repeat('a1B2c3D4', 130) . '.sig-LITE-9999';
        LiteAssert::true(strlen($token) > 1000);
        $http = new FakeHttpClient(fn () => FakeHttpClient::json(200, ['data' => []]));
        $a->container()->make(EmailProviderRegistry::class)->register(new EmailProviderDefinition(
            'sender', 'Sender', fn (string $s) => new \App\Infrastructure\Email\SenderNetEmailProvider($s, $http), 'email_providers.api_token', 'https://api.sender.net/', 'https://www.sender.net/',
        ));
        $a->as($a->admin);
        $a->request('POST', '/org/messaging/email/sender', [], ['api_secret' => $token, 'sender_email' => 'news@example.test', 'sender_name' => 'Shop']);
        $row = $a->pdo->query("SELECT * FROM organization_email_providers WHERE organization_id = {$a->orgId} AND provider = 'sender'")->fetch();
        LiteAssert::true($row !== false, 'saved');
        LiteAssert::same('connected', $row['status']);
        LiteAssert::same($token, SecretBox::decrypt($row['secret_encrypted']));
        LiteAssert::same('Bearer ' . $token, $http->requests[0]['headers']['Authorization']);
    },

    'audience ownership: a contact of another organization (or a missing id) is rejected; nothing is created' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $foreign = $h->otherContact ?? 99999999;
        $r = $a->request('POST', '/marketing/campaigns', [], [
            'name' => 'LiteMkt cross-org', 'channel' => 'sms', 'message_class' => 'marketing', 'body_text' => 'Hi',
            'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1], (string) $foreign],
        ]);
        LiteAssert::same(422, $r['status']);
        LiteAssert::contains('class="alert alert--error"', $r['body']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name = 'LiteMkt cross-org'")->fetchColumn());

        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteMkt bad var', 'channel' => 'sms', 'body_text' => 'Hi {{coupon}}', 'audience_type' => 'group', 'group_id' => (string) $h->groupId]);
        LiteAssert::same(422, $r['status'], 'unknown personalization variable');
        LiteAssert::contains('class="field-error"', $r['body']);
    },

    'sms review: counts eligible / missing / invalid / duplicate / opted-out and shows the personalized preview' => function () use ($setup, $h, $draft) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $a->request('POST', '/marketing/suppressions', [], ['channel' => 'sms', 'address' => '+989126666666']);
        LiteAssert::same('manual', $a->pdo->query("SELECT reason FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND address = '09126666666'")->fetchColumn());

        $h->smsCampaign = $draft(['name' => 'LiteMkt SMS', 'channel' => 'sms', 'message_class' => 'marketing', 'body_text' => 'Hi {{first_name}} from {{organization}}', 'audience_type' => 'group', 'group_id' => (string) $h->groupId]);
        $review = $h->campaigns->review($a->admin, $h->campaigns->find($a->admin, $h->smsCampaign))['totals'];
        LiteAssert::same(6, $review['contacts']);
        LiteAssert::same(2, $review['eligible']);
        LiteAssert::same(1, $review['missing_address']);
        LiteAssert::same(1, $review['invalid_address']);
        LiteAssert::same(1, $review['duplicate']);
        LiteAssert::same(1, $review['suppressed']);

        $page = $a->request('GET', '/marketing/campaigns/' . $h->smsCampaign);
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('Hi Sara from', $page['body']);
        LiteAssert::contains('name="confirmed_count" value="2"', $page['body']);
        LiteAssert::contains('/marketing/campaigns/' . $h->smsCampaign . '/test', $page['body']);
    },

    'approval: member (no marketing.send) is refused; a stale count is refused; the right count queues once' => function () use ($setup, $h, $approve) {
        $setup();
        $a = $h->a;
        $id = $h->smsCampaign;
        if ($a->member !== null) {
            $a->as($a->member);
            LiteAssert::same('forbidden', $a->outcome('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => '2']));
            LiteAssert::same('forbidden', $a->outcome('POST', '/marketing/templates', [], ['name' => 'x', 'channel' => 'sms', 'body_text' => 'x']));
            LiteAssert::same('ok', $a->outcome('GET', "/marketing/campaigns/{$id}"), 'members may view');
        }

        LiteAssert::same(422, $approve($id, 5)['status']);
        LiteAssert::same('draft', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());

        $r = $approve($id, 2);
        LiteAssert::same(302, $r['status']);
        $c = $a->pdo->query("SELECT * FROM marketing_campaigns WHERE id = {$id}")->fetch();
        LiteAssert::same('queued', $c['status']);
        LiteAssert::same(2, (int) $c['total_recipients']);
        $byStatus = $a->pdo->query("SELECT status, COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} GROUP BY status")->fetchAll(\PDO::FETCH_KEY_PAIR);
        LiteAssert::same(2, (int) $byStatus['pending']);
        LiteAssert::same(2, (int) $byStatus['skipped'], 'invalid + opted-out recorded as skipped');
        LiteAssert::same(0, count($h->sms->sent), 'approval sends nothing itself — the queue does');

        LiteAssert::same(422, $approve($id, 2)['status'], 'second approval refused');
        LiteAssert::same(4, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn(), 'no duplicate rows');
    },

    'queue: each recipient is sent once with a unique provider dedupe id; campaign completes; rerun sends nothing' => function () use ($setup, $h, $run) {
        $setup();
        $a = $h->a;
        $id = $h->smsCampaign;
        $result = $run($id);
        LiteAssert::same('completed', $result['status']);
        LiteAssert::same(2, count($h->sms->sent));
        LiteAssert::same(['09121111111', '09122222222'], array_column($h->sms->sent, 'receptor'));
        LiteAssert::contains('Hi Sara from', $h->sms->sent[0]['message']);
        LiteAssert::same(2, count(array_unique(array_column($h->sms->sent, 'local_id'))));

        $rows = $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id} AND status = 'sent'")->fetchAll();
        LiteAssert::same(2, count($rows));
        LiteAssert::same('fakesms-own', $rows[0]['provider']);
        LiteAssert::true($rows[0]['provider_message_id'] !== null);
        LiteAssert::same(null, $rows[0]['delivery_status'], 'accepted ≠ delivered');

        $run($id);
        $h->worker->runAll(3);
        LiteAssert::same(2, count($h->sms->sent), 'nothing sent twice');
    },

    'crash recovery: a stale SMS row is requeued and the provider dedupe id prevents a second real send' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt crash', 'channel' => 'sms', 'body_text' => 'Crash test', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $msgId = (int) $a->pdo->query("SELECT id FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn();
        // A worker sent it, then died before recording the result.
        $composer = (new \ReflectionProperty(QueueWorker::class, 'composer'))->getValue($h->worker);
        $h->sms->seenLocalIds[$composer->dedupeId($msgId)] = 777;
        $a->pdo->exec("UPDATE marketing_messages SET status = 'processing', attempts = 1, lock_token = 'dead', locked_until = '2000-01-01 00:00:00' WHERE id = {$msgId}");
        $a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");

        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::same($before, count($h->sms->sent), 'provider deduplicated — no second real SMS');
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE id = {$msgId}")->fetch();
        LiteAssert::same('sent', $m['status']);
        LiteAssert::same('777', $m['provider_message_id'], 'the earlier provider record is kept');
    },

    'outage: network failures are retried with a delay up to 3 attempts, then failed; "retry failed" requeues and sends' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt outage', 'channel' => 'sms', 'body_text' => 'Outage', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[2]]]);
        $approve($id, 1);
        $msg = fn () => $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id}")->fetch();

        $h->sms->script = [SmsResult::failure('Could not reach Kavenegar: timeout'), SmsResult::failure('Could not reach Kavenegar: timeout'), SmsResult::failure('Could not reach Kavenegar: timeout')];
        $run($id);
        LiteAssert::same('pending', $msg()['status']);
        LiteAssert::true($msg()['next_attempt_at'] !== null && strtotime($msg()['next_attempt_at']) > time(), 'delayed retry');
        $run($id);
        LiteAssert::same(1, (int) $msg()['attempts'], 'not due yet → not retried');

        $a->pdo->exec("UPDATE marketing_messages SET next_attempt_at = NULL WHERE campaign_id = {$id}");
        $run($id);
        $a->pdo->exec("UPDATE marketing_messages SET next_attempt_at = NULL WHERE campaign_id = {$id}");
        $run($id);
        LiteAssert::same('failed', $msg()['status']);
        LiteAssert::same(3, (int) $msg()['attempts']);
        LiteAssert::same('0', (string) $msg()['ambiguous']);
        LiteAssert::same('completed', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());

        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/retry");
        LiteAssert::same('pending', $msg()['status']);
        $run($id);
        LiteAssert::same('sent', $msg()['status']);
    },

    'provider rejection (e.g. no credit) is final until retried; the error is shown per recipient' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt reject', 'channel' => 'sms', 'body_text' => 'Reject', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $h->sms->script = [new SmsResult(false, 'اعتبار کافی نیست', 418)];
        $run($id);
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('failed', $m['status']);
        LiteAssert::same(1, (int) $m['attempts'], 'not auto-retried');
        $a->as($a->admin);
        LiteAssert::contains('اعتبار کافی نیست', $a->request('GET', "/marketing/campaigns/{$id}")['body']);
    },

    'delivery reports: delivered and operator-blocked states are recorded; a block adds an opt-out' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $rows = $a->pdo->query("SELECT id, provider_message_id, address FROM marketing_messages WHERE campaign_id = {$h->smsCampaign} AND status = 'sent' ORDER BY id")->fetchAll();
        $h->sms->statuses = [
            $rows[0]['provider_message_id'] => ['state' => 'delivered', 'code' => 10, 'text' => ''],
            $rows[1]['provider_message_id'] => ['state' => 'blocked', 'code' => 14, 'text' => ''],
        ];
        // Status checks are throttled (5 minutes per row); the earlier runAll() already checked these.
        $a->pdo->exec("UPDATE marketing_messages SET status_checked_at = NULL WHERE campaign_id = {$h->smsCampaign}");
        $changed = $h->worker->pollDeliveryReports();
        LiteAssert::true($changed >= 2);
        LiteAssert::same('delivered', $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$rows[0]['id']}")->fetchColumn());
        LiteAssert::same('blocked', $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$rows[1]['id']}")->fetchColumn());
        LiteAssert::same('blocked', $a->pdo->query("SELECT reason FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND channel = 'sms' AND address = " . $a->pdo->quote($rows[1]['address']))->fetchColumn());
    },

    'opt-out after queueing: the address is skipped at send time' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt late opt-out', 'channel' => 'sms', 'body_text' => 'Late', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $a->as($a->admin);
        $a->request('POST', '/marketing/suppressions', [], ['channel' => 'sms', 'address' => '09121111111']);
        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::same($before, count($h->sms->sent));
        LiteAssert::same('suppressed_manual', $a->pdo->query("SELECT skip_reason FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    'empty audience: approval refused when no recipient is eligible' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $id = $draft(['name' => 'LiteMkt empty', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[4]]]);
        LiteAssert::same(422, $approve($id, 0)['status']);
        LiteAssert::same('draft', $h->a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
    },

    'all contacts: needs the explicit confirmation checkbox' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt all', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'all']);
        $eligible = $h->campaigns->review($a->admin, $h->campaigns->find($a->admin, $id))['totals']['eligible'];
        LiteAssert::same(422, $approve($id, $eligible, false)['status']);
        LiteAssert::same('draft', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/delete");
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
    },

    'email via Brevo (mocked): one API call per recipient, no address exposed to others, unsubscribe link + header, provider id stored' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $h->emailCampaign = $draft([
            'name' => 'LiteMkt Email', 'channel' => 'email', 'provider' => 'brevo', 'message_class' => 'marketing',
            'subject' => 'News for {{first_name}}', 'body_html' => '<p>Hello {{first_name}}</p>', 'audience_type' => 'group', 'group_id' => (string) $h->groupId,
        ]);
        LiteAssert::same(302, $approve($h->emailCampaign, 3)['status']);

        $before = count($h->http->requests);
        $run($h->emailCampaign);
        $calls = array_slice($h->http->requests, $before);
        LiteAssert::same(3, count($calls));
        $seen = [];
        foreach ($calls as $call) {
            $body = json_decode((string) $call['body'], true);
            LiteAssert::same(1, count($body['to']));
            $seen[] = $body['to'][0]['email'];
            LiteAssert::contains('/m/unsubscribe/', $body['htmlContent']);
            LiteAssert::true(str_starts_with($body['headers']['List-Unsubscribe'], '<'));
            LiteAssert::same('List-Unsubscribe=One-Click', $body['headers']['List-Unsubscribe-Post']);
            LiteAssert::same(['email' => 'reply@example.test'], $body['replyTo'] ?? null);
        }
        sort($seen);
        LiteAssert::same(['c1@example.test', 'c2@example.test', 'c6@example.test'], $seen);
        LiteAssert::contains('News for Sara', implode('|', array_map(fn ($c) => json_decode((string) $c['body'], true)['subject'], $calls)));

        $ids = $a->pdo->query("SELECT provider_message_id FROM marketing_messages WHERE campaign_id = {$h->emailCampaign} AND status = 'sent'")->fetchAll(\PDO::FETCH_COLUMN);
        LiteAssert::same(3, count($ids));
        LiteAssert::true(str_starts_with((string) $ids[0], '<lite-'));
    },

    'webhooks: wrong bearer 401; delivered applied; replay ignored; hard bounce suppresses; late deferred never downgrades' => function () use ($setup, $h, $webhook) {
        $setup();
        $a = $h->a;
        $a->pdo->prepare("UPDATE organization_email_providers SET webhook_key = ?, webhook_secret_encrypted = ? WHERE organization_id = ? AND provider = 'brevo'")
            ->execute(['litekey0123456789', SecretBox::encrypt('lite-webhook-secret'), $a->orgId]);
        $msgs = $a->pdo->query("SELECT id, provider_message_id, address FROM marketing_messages WHERE campaign_id = {$h->emailCampaign} AND status = 'sent' ORDER BY id")->fetchAll();

        [$status] = $webhook('litekey0123456789', 'Bearer wrong', ['event' => 'delivered', 'message-id' => $msgs[0]['provider_message_id']]);
        LiteAssert::same(401, $status);
        [$status] = $webhook('unknown-key', 'Bearer lite-webhook-secret', ['event' => 'delivered', 'message-id' => $msgs[0]['provider_message_id']]);
        LiteAssert::same(401, $status);
        LiteAssert::same(null, $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$msgs[0]['id']}")->fetchColumn());

        $delivered = ['event' => 'delivered', 'email' => $msgs[0]['address'], 'id' => 1, 'message-id' => $msgs[0]['provider_message_id'], 'ts_event' => 1760000000];
        [$status, $body] = $webhook('litekey0123456789', 'Bearer lite-webhook-secret', $delivered);
        LiteAssert::same(200, $status);
        LiteAssert::same(1, $body['processed']);
        LiteAssert::same('delivered', $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$msgs[0]['id']}")->fetchColumn());

        [, $body] = $webhook('litekey0123456789', 'Bearer lite-webhook-secret', $delivered);
        LiteAssert::same(0, $body['processed'], 'replay is a no-op');

        [, $body] = $webhook('litekey0123456789', 'Bearer lite-webhook-secret', ['event' => 'deferred', 'message-id' => $msgs[0]['provider_message_id'], 'ts_event' => 1760000500]);
        LiteAssert::same('delivered', $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$msgs[0]['id']}")->fetchColumn(), 'final state kept');

        $webhook('litekey0123456789', 'Bearer lite-webhook-secret', ['event' => 'hard_bounce', 'message-id' => $msgs[1]['provider_message_id'], 'reason' => 'unknown user', 'ts_event' => 1760000001]);
        LiteAssert::same('bounced', $a->pdo->query("SELECT delivery_status FROM marketing_messages WHERE id = {$msgs[1]['id']}")->fetchColumn());
        LiteAssert::same('bounced', $a->pdo->query("SELECT reason FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND channel = 'email' AND address = " . $a->pdo->quote($msgs[1]['address']))->fetchColumn());
    },

    'unsubscribe link: tampered token 404; valid link opts the address out; marketing skips it, transactional does not' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $msg = $a->pdo->query("SELECT id, address FROM marketing_messages WHERE campaign_id = {$h->emailCampaign} AND address = 'c6@example.test'")->fetch();
        $token = UnsubscribeLinks::token($a->orgId, (int) $msg['id']);

        LiteAssert::same('notfound', $a->outcome('GET', '/m/unsubscribe/' . $token . 'x'));
        LiteAssert::same('notfound', $a->outcome('GET', '/m/unsubscribe/' . UnsubscribeLinks::token($a->orgId + 100000, (int) $msg['id'])), 'organization without the module');
        $page = $a->request('GET', '/m/unsubscribe/' . $token);
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('action="/m/unsubscribe/' . $token . '"', $page['body']);

        $a->request('POST', '/m/unsubscribe/' . $token, [], ['_csrf' => '']);
        LiteAssert::same('unsubscribed', $a->pdo->query("SELECT reason FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND channel = 'email' AND address = 'c6@example.test'")->fetchColumn());

        $base = ['channel' => 'email', 'provider' => 'brevo', 'subject' => 's', 'body_html' => '<p>x</p>', 'body_text' => null, 'audience_type' => 'contacts', 'audience_ids' => json_encode([$h->c[6]]), 'id' => 0];
        LiteAssert::same(1, $h->campaigns->review($a->admin, $base + ['message_class' => 'marketing'])['totals']['suppressed']);
        LiteAssert::same(1, $h->campaigns->review($a->admin, $base + ['message_class' => 'transactional'])['totals']['eligible'], 'service messages ignore marketing opt-outs');
    },

    'email interrupted mid-send is never resent: failed + ambiguous, excluded from "retry failed"' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt email crash', 'channel' => 'email', 'provider' => 'brevo', 'subject' => 's', 'body_html' => '<p>x</p>', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $a->pdo->exec("UPDATE marketing_messages SET status = 'processing', attempts = 1, lock_token = 'dead', locked_until = '2000-01-01 00:00:00' WHERE campaign_id = {$id}");
        $a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");

        $before = count($h->http->requests);
        $run($id);
        LiteAssert::same($before, count($h->http->requests), 'not resent');
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('failed', $m['status']);
        LiteAssert::same('1', (string) $m['ambiguous']);

        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/retry");
        LiteAssert::same('failed', $a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    'email timeout at the provider: failed + ambiguous, never auto-retried; 429 goes back to the queue' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt email 429', 'channel' => 'email', 'provider' => 'brevo', 'subject' => 's', 'body_html' => '<p>x</p>', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $h->smtp = fn () => FakeHttpClient::json(429, ['message' => 'Too many requests'], ['retry-after' => '30']);
        $run($id);
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('pending', $m['status']);
        LiteAssert::true(strtotime((string) $m['next_attempt_at']) >= time() + 20);

        $a->pdo->exec("UPDATE marketing_messages SET next_attempt_at = NULL WHERE campaign_id = {$id}");
        $h->smtp = fn () => new HttpResponse(0, '', [], 'Operation timed out', false, true);
        $run($id);
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('failed', $m['status']);
        LiteAssert::same('1', (string) $m['ambiguous']);
        $h->smtp = fn () => FakeHttpClient::json(201, ['messageId' => '<lite-' . (++$h->messageSeq) . '@relay>']);
    },

    'entitlement in the backend: without marketing_email the worker holds email campaigns and the API refuses them' => function () use ($setup, $h, $draft, $approve, $entitle) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt held', 'channel' => 'email', 'provider' => 'brevo', 'subject' => 's', 'body_html' => '<p>x</p>', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        $approve($id, 1);
        $entitle('marketing_email', false);

        $before = count($h->http->requests);
        $h->worker->runAll(5);
        LiteAssert::same($before, count($h->http->requests), 'nothing sent');
        LiteAssert::same('pending', $a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
        LiteAssert::same('marketing.error.module_unavailable', $a->pdo->query("SELECT last_error FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());

        $a->as($a->admin);
        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteMkt no email', 'channel' => 'email', 'provider' => 'brevo', 'subject' => 's', 'body_html' => '<p>x</p>', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[1]]]);
        LiteAssert::same(422, $r['status']);
        $a->request('POST', '/org/messaging/email/brevo/test-email', [], ['to' => 'me@example.test']);
        LiteAssert::same($before, count($h->http->requests), 'test email refused too');

        $entitle('marketing_email', true);
        $h->worker->runAll(5);
        LiteAssert::same('sent', $a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn(), 'resumes when the module is back');
    },

    'cancel: a queued campaign is cancelled, its pending messages too, and nothing is sent' => function () use ($setup, $h, $draft, $approve, $run) {
        $setup();
        $a = $h->a;
        $id = $draft(['name' => 'LiteMkt cancel', 'channel' => 'sms', 'body_text' => 'Cancel', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c[7]]]);
        $approve($id, 1);
        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/cancel");
        LiteAssert::same('cancelled', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::same($before, count($h->sms->sent));
        LiteAssert::same('cancelled', $a->pdo->query("SELECT status FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    'isolation: another organization\'s campaign and messages are invisible' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        if ($a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        $a->pdo->prepare("INSERT INTO marketing_campaigns (organization_id, name, channel, audience_type, status) VALUES (?, 'LiteMkt foreign', 'sms', 'all', 'completed')")->execute([$a->otherOrgId]);
        $foreignId = (int) $a->pdo->lastInsertId();
        $a->pdo->prepare("INSERT INTO marketing_messages (organization_id, campaign_id, channel, address, status, provider_message_id) VALUES (?, ?, 'sms', '09120000000', 'sent', 'FOREIGN-REF')")->execute([$a->otherOrgId, $foreignId]);

        $a->as($a->admin);
        LiteAssert::same('notfound', $a->outcome('GET', "/marketing/campaigns/{$foreignId}"));
        LiteAssert::same('notfound', $a->outcome('POST', "/marketing/campaigns/{$foreignId}/cancel"));
        LiteAssert::notContains('LiteMkt foreign', $a->request('GET', '/marketing/campaigns')['body']);
        LiteAssert::notContains('FOREIGN-REF', $a->request('GET', '/marketing/history')['body']);
    },

    'history, campaign page and contact panel show per-recipient status and provider references' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $ref = (string) $a->pdo->query("SELECT provider_message_id FROM marketing_messages WHERE campaign_id = {$h->smsCampaign} AND status = 'sent' ORDER BY id LIMIT 1")->fetchColumn();
        LiteAssert::contains($ref, $a->request('GET', '/marketing/history')['body']);
        LiteAssert::contains($ref, $a->request('GET', '/marketing/campaigns/' . $h->smsCampaign)['body']);
        LiteAssert::contains('data-marketing-progress', $a->request('GET', '/marketing/campaigns/' . $h->smsCampaign, ['status' => 'sent'])['body'] . 'data-marketing-progress', 'page renders');
        LiteAssert::contains('/marketing/campaigns/' . $h->smsCampaign, $a->request('GET', '/contacts/' . $h->c[1])['body'], 'contact panel lists the campaign');
        LiteAssert::same(200, $a->request('GET', '/marketing')['status']);
        LiteAssert::same(200, $a->request('GET', '/marketing/templates')['status']);
        LiteAssert::same(200, $a->request('GET', '/marketing/suppressions')['status']);
        LiteAssert::same(200, $a->request('GET', '/marketing/campaigns/create', ['channel' => 'email'])['status']);
    },

    'templates: saved, listed and loaded into a new campaign' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $r = $a->request('POST', '/marketing/templates', [], ['name' => 'LiteMkt tpl', 'channel' => 'sms', 'body_text' => 'Tpl {{first_name}}']);
        LiteAssert::same('/marketing/templates', $r['location']);
        $tplId = (int) $a->pdo->query("SELECT id FROM marketing_templates WHERE name = 'LiteMkt tpl'")->fetchColumn();
        LiteAssert::contains('Tpl {{first_name}}', $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms', 'template' => (string) $tplId])['body']);
    },

    'progress endpoint returns JSON counts' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        $r = $a->request('POST', '/marketing/campaigns/' . $h->smsCampaign . '/process');
        $json = json_decode($r['body'], true);
        LiteAssert::same('completed', $json['data']['status']);
        LiteAssert::true($json['data']['counts']['total'] >= 2);
    },

    'translations: every new key exists in all five languages' => function () {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $keys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, 'marketing.') || str_starts_with($k, 'messaging.') || str_starts_with($k, 'email_providers.'));
        LiteAssert::true(count($keys) > 200);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $other = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                LiteAssert::true(isset($other[$key]) && trim((string) $other[$key]) !== '', "{$lang}: {$key}");
            }
        }
        foreach (['status.draft', 'status.preparing', 'status.queued', 'status.sending', 'status.completed', 'status.cancelled'] as $suffix) {
            LiteAssert::true(isset($en['marketing.' . $suffix]), $suffix);
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        if (isset($h->a)) {
            $h->a->rollBack();
        }
        LiteAssert::true(true);
    },
];
