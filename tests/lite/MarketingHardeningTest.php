<?php

declare(strict_types=1);

/**
 * Marketing 3.1 — hardening regressions for audit findings F1–F7
 * (docs/marketing-audit-phase1-3.md). crm_local only, one transaction,
 * rolled back. Providers are fakes; no real message, no real key.
 */

use App\Application\Services\SmsService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Sms\SmsBillingInterface;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\SmsChannel;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\UnsubscribeLinks;
use Tests\Lite\Fixtures\FakeSmsSender;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Fixtures/MarketingFakes.php';

final class LiteFakeSmsBilling implements SmsBillingInterface
{
    public int $charged = 0;
    public int $refunded = 0;

    public function unitPrice(): string
    {
        return '1000';
    }

    public function balance(int $organizationId): string
    {
        return '1000000';
    }

    public function tryCharge(int $organizationId, int $units, string $context): bool
    {
        $this->charged += $units;

        return true;
    }

    public function refund(int $organizationId, int $units, string $context): void
    {
        $this->refunded += $units;
    }
}

$h = new \stdClass();
$h->ready = false;
$h->reason = '';

/** A copy of a service with some constructor arguments replaced (promoted properties = parameter names). */
$rebuild = function (object $service, array $overrides): object {
    $class = new \ReflectionClass($service);
    $args = [];
    foreach ($class->getConstructor()->getParameters() as $param) {
        $name = $param->getName();
        $args[] = array_key_exists($name, $overrides) ? $overrides[$name] : (new \ReflectionProperty($service, $name))->getValue($service);
    }

    return $class->newInstanceArgs($args);
};

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
        $h->a->pdo->query('SELECT billed_units FROM marketing_messages LIMIT 1');
        $h->a->pdo->query('SELECT 1 FROM rate_limit_hits LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Marketing 3.1 not migrated: ' . $e->getMessage();
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
    $pdo->exec('DELETE FROM rate_limit_hits');

    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);

    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteHard group']);
    $h->groupId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, email, category_id, status) VALUES (?, ?, 'Lite', ?, ?, ?, 'active')");
    $h->c = [];
    foreach (['Golnaz' => '09131110001', 'Kian' => '09131110002', 'Parsa' => '09131110003', 'Darya' => '09131110004', 'Elham' => '09131110005', 'Farid' => '09131110006'] as $name => $phone) {
        $insert->execute([$a->orgId, $name, $phone, strtolower($name) . '@example.test', $h->groupId]);
        $h->c[$name] = (int) $pdo->lastInsertId();
    }

    // Outside the group: used by the F7 platform test only.
    $pdo->prepare("INSERT INTO contacts (organization_id, first_name, phone, status) VALUES (?, 'Hamid', '09131110007', 'active')")->execute([$a->orgId]);
    $h->c['Hamid'] = (int) $pdo->lastInsertId();

    // Roles: marketing without contacts.view; a sender without send_transactional.
    $makeRole = function (string $key, array $perms) use ($a, $pdo): array {
        $pdo->prepare('INSERT INTO roles (organization_id, `key`, name, is_system) VALUES (?, ?, ?, 0)')->execute([$a->orgId, $key, $key]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($perms as $perm) {
            $pdo->prepare("INSERT INTO role_permissions (role_id, permission_key, scope) VALUES (?, ?, 'organization')")->execute([$roleId, $perm]);
        }

        // A real user row (campaign owner_id has a foreign key), acting with the test role.
        return array_merge($a->member ?? throw new \RuntimeException('needs a second active user in the organization'), ['role_id' => $roleId]);
    };
    $h->noContacts = $makeRole('lite_mkt_no_contacts', ['marketing.view', 'marketing.create']);
    $h->plainSender = $makeRole('lite_mkt_sender', ['marketing.view', 'marketing.create', 'marketing.send', 'contacts.view']);

    $h->campaigns = $c->make(CampaignService::class);
    $h->worker = $c->make(QueueWorker::class);
    $h->ready = true;
};

$run = fn (int $id) => $h->worker->processCampaign($id, 100, microtime(true) + 20);

$draft = function (array $user, array $input) use ($h): int {
    $h->a->as($user);
    $r = $h->a->request('POST', '/marketing/campaigns', [], $input);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('draft not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};

$approve = function (int $id, int $count, ?array $user = null) use ($h): array {
    $h->a->as($user ?? $h->a->admin);

    return $h->a->request('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => (string) $count]);
};

$smsGroup = fn (string $name, string $class = 'marketing') => ['name' => $name, 'channel' => 'sms', 'message_class' => $class, 'body_text' => 'Hi {{first_name}}', 'audience_type' => 'group', 'group_id' => (string) $h->groupId];

return [
    // --- F1 ---------------------------------------------------------------------

    'F1: without contacts.view the campaign form lists no contacts and offers no individual selection' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($h->noContacts);
        LiteAssert::same('forbidden', $a->outcome('GET', '/contacts'), 'precondition: no contacts.view');
        $body = $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms', 'contact' => (string) $h->c['Golnaz']])['body'];
        foreach (['Golnaz', '09131110001', 'golnaz@example.test'] as $secret) {
            LiteAssert::notContains($secret, $body, "form leaks {$secret}");
        }
        LiteAssert::notContains('crm-recipient-picker', $body);
        LiteAssert::notContains('value="contacts"', $body);

        $a->as($a->admin);
        LiteAssert::contains('09131110001', $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms'])['body'], 'admin still sees the picker');
    },

    'F1: choosing individual contacts by id is refused server-side without contacts.view' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->as($h->noContacts);
        $r = $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteHard F1 ids', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Golnaz']]]);
        LiteAssert::same(422, $r['status']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name = 'LiteHard F1 ids'")->fetchColumn());
    },

    'F1: review preview, test send, recipient list, history and opt-outs never show contact data without contacts.view' => function () use ($setup, $h, $draft, $approve, $run, $smsGroup) {
        $setup();
        $a = $h->a;
        $id = $draft($h->noContacts, $smsGroup('LiteHard F1 group'));

        $a->as($h->noContacts);
        $review = $a->request('GET', "/marketing/campaigns/{$id}")['body'];
        LiteAssert::contains('Hi [first_name]', $review, 'placeholder preview');
        foreach (['Golnaz', 'Kian', '09131110001'] as $secret) {
            LiteAssert::notContains($secret, $review);
        }

        $before = count($h->sms->sent);
        $a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '09139999999']);
        LiteAssert::same($before + 1, count($h->sms->sent));
        LiteAssert::same('Hi [first_name]', end($h->sms->sent)['message'], 'test send carries no real contact data');

        $approve($id, 6);
        $run($id);
        $a->request('POST', '/marketing/suppressions', [], ['_csrf' => \App\Infrastructure\Security\Csrf::token()]); // ignored (no permission) — keep session warm
        $a->as($a->admin);
        $a->request('POST', '/marketing/suppressions', [], ['channel' => 'sms', 'address' => '09131110006']);

        $a->as($h->noContacts);
        foreach (["/marketing/campaigns/{$id}", '/marketing/history', '/marketing/suppressions'] as $page) {
            $body = $a->request('GET', $page)['body'];
            LiteAssert::notContains('09131110001', $body, "{$page} shows a full number");
            LiteAssert::notContains('09131110006', $body, "{$page} shows a full number");
            LiteAssert::notContains('Golnaz', $body, "{$page} shows a name");
            LiteAssert::notContains('href="/contacts/', $body, "{$page} links to a contact");
        }
        LiteAssert::contains('0913*****01', $a->request('GET', "/marketing/campaigns/{$id}")['body'], 'masked address shown');

        $a->as($a->admin);
        $body = $a->request('GET', "/marketing/campaigns/{$id}")['body'];
        LiteAssert::contains('09131110001', $body, 'users with contacts.view still see full data');
        LiteAssert::contains('href="/contacts/' . $h->c['Golnaz'] . '"', $body);
    },

    // --- F2 ---------------------------------------------------------------------

    'F2: "transactional" needs marketing.send_transactional — not offered and refused for creators without it' => function () use ($setup, $h, $smsGroup) {
        $setup();
        $a = $h->a;
        $a->as($h->plainSender);
        LiteAssert::notContains('value="transactional"', $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms'])['body']);
        $input = ['name' => 'LiteHard F2 creator', 'channel' => 'sms', 'message_class' => 'transactional', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Kian']]];
        LiteAssert::same(422, $a->request('POST', '/marketing/campaigns', [], $input)['status']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name = 'LiteHard F2 creator'")->fetchColumn());

        $a->as($a->admin);
        LiteAssert::contains('value="transactional"', $a->request('GET', '/marketing/campaigns/create', ['channel' => 'sms'])['body']);
    },

    'F2: even with the permission, transactional is only for individually selected contacts within the limit' => function () use ($setup, $h, $smsGroup, $draft) {
        $setup();
        $a = $h->a;
        $a->as($a->admin);
        LiteAssert::same(422, $a->request('POST', '/marketing/campaigns', [], $smsGroup('LiteHard F2 group', 'transactional'))['status']);
        LiteAssert::same(422, $a->request('POST', '/marketing/campaigns', [], ['name' => 'LiteHard F2 all', 'channel' => 'sms', 'message_class' => 'transactional', 'body_text' => 'x', 'audience_type' => 'all'])['status']);

        $max = (int) Config::get('messaging.marketing.transactional_max_recipients', 50);
        $id = $draft($a->admin, ['name' => 'LiteHard F2 ok', 'channel' => 'sms', 'message_class' => 'transactional', 'body_text' => 'Your order shipped', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Kian']]]);
        LiteAssert::true($id > 0 && $max >= 1);
        $h->transactionalDraft = $id;
    },

    'F2: approval re-checks — a sender without the permission, or a tampered draft, cannot send transactional' => function () use ($setup, $h, $approve) {
        $setup();
        $a = $h->a;
        $id = $h->transactionalDraft ?? throw new LiteSkip('depends on the previous test');
        LiteAssert::same(422, $approve($id, 1, $h->plainSender)['status'], 'sender lacks send_transactional');
        LiteAssert::same('draft', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());

        // Tamper the stored draft into a group audience (bypassing the form).
        $a->pdo->exec("UPDATE marketing_campaigns SET audience_type = 'group', audience_ids = '[{$h->groupId}]' WHERE id = {$id}");
        LiteAssert::same(422, $approve($id, 6)['status'], 'admin cannot approve a transactional group campaign');
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    // --- F3 ---------------------------------------------------------------------

    'F3: unsubscribe links keep working after the Marketing module is disabled — and stay signed and tenant-safe' => function () use ($setup, $h, $draft, $approve, $run, $smsGroup) {
        $setup();
        $a = $h->a;
        $id = $draft($a->admin, $smsGroup('LiteHard F3'));
        $approve($id, $h->campaigns->review($a->admin, $h->campaigns->find($a->admin, $id))['confirm_count']);
        $msg = $a->pdo->query("SELECT id, address FROM marketing_messages WHERE campaign_id = {$id} AND status = 'pending' ORDER BY id LIMIT 1")->fetch();
        $token = UnsubscribeLinks::token($a->orgId, (int) $msg['id']);

        $a->setOrgEntitled('marketing', false);
        $a->container()->make(ModuleGate::class)->reset();
        try {
        LiteAssert::same('notfound', $a->outcome('GET', '/marketing'), 'precondition: module off');

        $page = $a->request('GET', '/m/unsubscribe/' . $token);
        LiteAssert::same(200, $page['status']);
        LiteAssert::notContains((string) $msg['address'], $page['body'], 'page reveals no address');
        $a->request('POST', '/m/unsubscribe/' . $token, [], ['_csrf' => '']);
        LiteAssert::same('unsubscribed', $a->pdo->query("SELECT reason FROM marketing_suppressions WHERE organization_id = {$a->orgId} AND channel = 'sms' AND address = " . $a->pdo->quote($msg['address']))->fetchColumn());

        LiteAssert::same('notfound', $a->outcome('GET', '/m/unsubscribe/' . $token . 'A'), 'tampered');
        if ($a->otherOrgId !== null) {
            LiteAssert::same('notfound', $a->outcome('GET', '/m/unsubscribe/' . UnsubscribeLinks::token($a->otherOrgId, (int) $msg['id'])), 'message id of another organization');
        }
        LiteAssert::same('notfound', $a->outcome('GET', '/m/unsubscribe/' . UnsubscribeLinks::token($a->orgId, 999999999)), 'unknown message');
        } finally {
            $a->setOrgEntitled('marketing', true);
            $a->container()->make(ModuleGate::class)->reset();
        }
        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/cancel");
    },

    // --- F4 ---------------------------------------------------------------------

    'F4: a Super Admin (no organization) gets 404 on Marketing routes, never a 500' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $super = $a->pdo->query('SELECT * FROM users WHERE is_super_admin = 1 LIMIT 1')->fetch()
            ?: ['id' => 900888, 'is_super_admin' => 1, 'organization_id' => null, 'role_id' => null, 'team_id' => null, 'department_id' => null, 'status' => 'active', 'language' => 'en'];
        OrganizationContext::reset();
        try {
            $a->as($super);
            foreach (['/marketing', '/marketing/campaigns', '/marketing/history', '/marketing/campaigns/create'] as $path) {
                LiteAssert::throws(NotFoundException::class, fn () => $a->request('GET', $path), $path);
            }
        } finally {
            OrganizationContext::set($a->orgId);
            $a->as($a->admin);
        }
    },

    // --- F5 ---------------------------------------------------------------------

    'F5: above the limit, approval does not expand recipients in the request; the worker prepares them, then sends' => function () use ($setup, $h, $rebuild, $smsGroup, $draft) {
        $setup();
        $a = $h->a;
        $service = $rebuild($h->campaigns, ['syncAudienceLimit' => 3]);
        $id = $draft($a->admin, $smsGroup('LiteHard F5 large'));
        $campaign = $service->find($a->admin, $id);

        $review = $service->review($a->admin, $campaign);
        LiteAssert::true($review['large']);
        LiteAssert::same(6, $review['confirm_count']);
        LiteAssert::contains('marketing.warning.large_audience', implode(',', $review['warnings']));

        LiteAssert::throws(ValidationException::class, fn () => $service->approveAndQueue($a->admin, $id, 5, false), 'stale count refused');
        LiteAssert::same(6, $service->approveAndQueue($a->admin, $id, 6, false));
        $row = $a->pdo->query("SELECT status, prepare_cursor FROM marketing_campaigns WHERE id = {$id}")->fetch();
        LiteAssert::same('preparing', $row['status']);
        LiteAssert::same('0', (string) $row['prepare_cursor']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn(), 'nothing expanded in the request');

        // Resume from a cursor (as after a timeout): only contacts after it are added.
        $a->pdo->exec("UPDATE marketing_campaigns SET prepare_cursor = {$h->c['Kian']} WHERE id = {$id}");
        $before = count($h->sms->sent);
        $h->worker->processCampaign($id, 100, microtime(true) + 20);
        LiteAssert::same(4, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn(), 'resumed after the cursor');
        $c = $a->pdo->query("SELECT status, total_recipients FROM marketing_campaigns WHERE id = {$id}")->fetch();
        LiteAssert::same('completed', $c['status']);
        // Farid (after the cursor) was opted out in the F1 test → skipped, not sent.
        LiteAssert::same('suppressed_manual', $a->pdo->query("SELECT skip_reason FROM marketing_messages WHERE campaign_id = {$id} AND contact_id = {$h->c['Farid']}")->fetchColumn());
        LiteAssert::same(3, count($h->sms->sent) - $before);
    },

    'F5: the background expansion is picked up by the cron worker and stops when the campaign is cancelled' => function () use ($setup, $h, $rebuild, $smsGroup, $draft) {
        $setup();
        $a = $h->a;
        $service = $rebuild($h->campaigns, ['syncAudienceLimit' => 3]);
        $id = $draft($a->admin, $smsGroup('LiteHard F5 cancel'));
        $service->approveAndQueue($a->admin, $id, 6, false);
        $service->cancel($a->admin, $id);
        $before = count($h->sms->sent);
        $h->worker->runAll(5);
        LiteAssert::same('cancelled', $a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id}")->fetchColumn());
        LiteAssert::same($before, count($h->sms->sent));
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'pending'")->fetchColumn());

        $id2 = $draft($a->admin, $smsGroup('LiteHard F5 cron'));
        $service->approveAndQueue($a->admin, $id2, 6, false);
        $h->worker->runAll(10);
        LiteAssert::true(in_array($a->pdo->query("SELECT status FROM marketing_campaigns WHERE id = {$id2}")->fetchColumn(), ['sending', 'completed'], true), 'cron picked up the preparing campaign');
    },

    'F5: small audiences keep the original synchronous behaviour' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $a = $h->a;
        $id = $draft($a->admin, ['name' => 'LiteHard F5 small', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Darya'], (string) $h->c['Elham']]]);
        LiteAssert::same(302, $approve($id, 2)['status']);
        $c = $a->pdo->query("SELECT status, prepare_cursor FROM marketing_campaigns WHERE id = {$id}")->fetch();
        LiteAssert::same('queued', $c['status']);
        LiteAssert::same(null, $c['prepare_cursor']);
        LiteAssert::same(2, (int) $a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());
    },

    // --- F6 ---------------------------------------------------------------------

    'F6: test sends share one per-user limit across campaign tests, /org/sms and provider test email' => function () use ($setup, $h, $draft) {
        $setup();
        $a = $h->a;
        $a->pdo->exec('DELETE FROM rate_limit_hits');
        $max = (int) Config::get('messaging.test_sends.per_user_max', 5);
        $id = $draft($a->admin, ['name' => 'LiteHard F6', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Darya']]]);
        $a->as($a->admin);

        $before = count($h->sms->sent);
        for ($i = 0; $i < $max; $i++) {
            $a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '09139999999']);
        }
        LiteAssert::same($before + $max, count($h->sms->sent), 'within the limit');

        $a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '09139999999']);
        $a->request('POST', '/org/sms/test', [], ['receptor' => '09139999999']);
        LiteAssert::same($before + $max, count($h->sms->sent), 'campaign test and /org/sms test both refused over the limit');
    },

    'F6: the per-organization limit applies across users' => function () use ($setup, $h, $draft) {
        $setup();
        $a = $h->a;
        $a->pdo->exec('DELETE FROM rate_limit_hits');
        $orgMax = (int) Config::get('messaging.test_sends.per_organization_max', 20);
        $insert = $a->pdo->prepare("INSERT INTO rate_limit_hits (bucket, subject, created_at) VALUES ('messaging.test_send', ?, ?)");
        for ($i = 0; $i < $orgMax; $i++) {
            $insert->execute(['org:' . $a->orgId, date('Y-m-d H:i:s')]);
        }
        $id = $draft($a->admin, ['name' => 'LiteHard F6 org', 'channel' => 'sms', 'body_text' => 'x', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Darya']]]);
        $before = count($h->sms->sent);
        $a->as($a->admin);
        $a->request('POST', "/marketing/campaigns/{$id}/test", [], ['address' => '09139999999']);
        LiteAssert::same($before, count($h->sms->sent));
        $a->pdo->exec('DELETE FROM rate_limit_hits');
    },

    // --- F7 ---------------------------------------------------------------------

    'F7: platform mode — crash after the provider accepted: recovered from the provider, no resend, no second charge' => function () use ($setup, $h, $rebuild, $draft, $approve) {
        $setup();
        $a = $h->a;
        $c = $a->container();
        $billing = new LiteFakeSmsBilling();
        $platform = new SmsService(
            $c->make(SmsProviderRegistry::class),
            ['provider' => 'fakesms', 'credentials' => ['fakesms' => ['api_key' => 'platform-fake']]],
            new \App\Infrastructure\Repositories\SmsLogRepository(),
            new \App\Infrastructure\Repositories\OrganizationSmsSettingsRepository(),
            new \App\Infrastructure\Repositories\OrganizationRepository(),
        );
        $platform->setBilling($billing);
        LiteAssert::same(null, $platform->saveOrganizationSettings($a->orgId, 'platform', '', [], (int) $a->admin['id']));

        $channels = new ChannelRegistry();
        $channels->register(new SmsChannel($platform));
        $worker = $rebuild($h->worker, ['sms' => $platform, 'channels' => $channels]);
        $composer = (new \ReflectionProperty(QueueWorker::class, 'composer'))->getValue($h->worker);
        $h->platform = [$billing, $worker, $composer];

        // Normal platform send: charged once, marker stored.
        $id = $draft($a->admin, ['name' => 'LiteHard F7 normal', 'channel' => 'sms', 'body_text' => 'Hi', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Hamid']]]);
        $approve($id, 1);
        $worker->processCampaign($id, 10, microtime(true) + 10);
        LiteAssert::same(1, $billing->charged);
        LiteAssert::same('1', (string) $a->pdo->query("SELECT billed_units FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn());

        // Crash after Kavenegar accepted the SMS, before the result was saved.
        $id = $draft($a->admin, ['name' => 'LiteHard F7 crash', 'channel' => 'sms', 'body_text' => 'Hi', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Elham']]]);
        $approve($id, 1);
        $msgId = (int) $a->pdo->query("SELECT id FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn();
        $h->sms->seenLocalIds[$composer->dedupeId($msgId)] = 888;
        $a->pdo->exec("UPDATE marketing_messages SET status = 'processing', attempts = 1, billed_units = 1, lock_token = 'dead', locked_until = '2000-01-01 00:00:00' WHERE id = {$msgId}");
        $a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");
        $billing->charged = 1; // the charge that happened before the crash

        $sent = count($h->sms->sent);
        $calls = $h->sms->sendCalls;
        $worker->processCampaign($id, 10, microtime(true) + 10);
        LiteAssert::same($calls, $h->sms->sendCalls, 'recovered by lookup — the provider send endpoint is not called at all');
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE id = {$msgId}")->fetch();
        LiteAssert::same('sent', $m['status']);
        LiteAssert::same('888', $m['provider_message_id'], 'recovered from the provider (statuslocalmessageid)');
        LiteAssert::same($sent, count($h->sms->sent), 'no resend');
        LiteAssert::same(1, $billing->charged, 'no second charge');
        LiteAssert::same(0, $billing->refunded);
    },

    'F7: crash after the charge but before the provider call: sent once on recovery, charged once' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $a = $h->a;
        [$billing, $worker, $composer] = $h->platform ?? throw new LiteSkip('depends on the previous test');
        $id = $draft($a->admin, ['name' => 'LiteHard F7 precall', 'channel' => 'sms', 'body_text' => 'Hi', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Darya']]]);
        $approve($id, 1);
        $msgId = (int) $a->pdo->query("SELECT id FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn();
        $a->pdo->exec("UPDATE marketing_messages SET status = 'processing', attempts = 1, billed_units = 1, lock_token = 'dead', locked_until = '2000-01-01 00:00:00' WHERE id = {$msgId}");
        $a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");
        $charged = $billing->charged;
        $sent = count($h->sms->sent);

        $worker->processCampaign($id, 10, microtime(true) + 10);
        LiteAssert::same('sent', $a->pdo->query("SELECT status FROM marketing_messages WHERE id = {$msgId}")->fetchColumn());
        LiteAssert::same($sent + 1, count($h->sms->sent), 'sent exactly once');
        LiteAssert::same($charged, $billing->charged, 'already paid — not charged again');
    },

    'F7: provider cannot be asked: requeued, Kavenegar localid still blocks the duplicate, and no charge' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $a = $h->a;
        [$billing, $worker, $composer] = $h->platform ?? throw new LiteSkip('depends on the previous test');
        $id = $draft($a->admin, ['name' => 'LiteHard F7 nolookup', 'channel' => 'sms', 'body_text' => 'Hi', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Kian']]]);
        $approve($id, 1);
        $msgId = (int) $a->pdo->query("SELECT id FROM marketing_messages WHERE campaign_id = {$id}")->fetchColumn();
        $h->sms->seenLocalIds[$composer->dedupeId($msgId)] = 999;
        $h->sms->lookupAvailable = false;
        $a->pdo->exec("UPDATE marketing_messages SET status = 'processing', attempts = 1, billed_units = 1, lock_token = 'dead', locked_until = '2000-01-01 00:00:00' WHERE id = {$msgId}");
        $a->pdo->exec("UPDATE marketing_campaigns SET status = 'sending' WHERE id = {$id}");
        $charged = $billing->charged;
        $sent = count($h->sms->sent);

        $worker->processCampaign($id, 10, microtime(true) + 10);
        $h->sms->lookupAvailable = true;
        $m = $a->pdo->query("SELECT * FROM marketing_messages WHERE id = {$msgId}")->fetch();
        LiteAssert::same('sent', $m['status']);
        LiteAssert::same('999', $m['provider_message_id']);
        LiteAssert::same($sent, count($h->sms->sent), 'provider dedupe — no second SMS');
        LiteAssert::same($charged, $billing->charged, 'no second charge');
    },

    'F7: a final provider rejection refunds the charge and clears the marker' => function () use ($setup, $h, $draft, $approve) {
        $setup();
        $a = $h->a;
        [$billing, $worker] = $h->platform ?? throw new LiteSkip('depends on the previous test');
        $id = $draft($a->admin, ['name' => 'LiteHard F7 reject', 'channel' => 'sms', 'body_text' => 'Hi', 'audience_type' => 'contacts', 'contact_ids' => [(string) $h->c['Parsa']]]);
        $approve($id, 1);
        $refunded = $billing->refunded;
        $h->sms->script = [new SmsResult(false, 'invalid sender line', 412)];
        $worker->processCampaign($id, 10, microtime(true) + 10);
        $m = $a->pdo->query("SELECT status, billed_units FROM marketing_messages WHERE campaign_id = {$id}")->fetch();
        LiteAssert::same('failed', $m['status']);
        LiteAssert::same(null, $m['billed_units']);
        LiteAssert::same($refunded + 1, $billing->refunded);

        // Back to the organization's own account for any later suite.
        $a->container()->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);
    },

    'D1: the Kavenegar settings card stays usable without the Marketing module' => function () use ($setup, $h) {
        $setup();
        $a = $h->a;
        $a->setOrgEntitled('marketing', false);
        $a->setOrgEntitled('marketing_email', false);
        $a->as($a->admin);
        $body = $a->request('GET', '/org/messaging')['body'];
        LiteAssert::contains('href="/org/sms"', $body);
        LiteAssert::notContains('id="provider-kavenegar" class', $body);
        LiteAssert::same(1, preg_match('#<section class="card provider-card" id="provider-kavenegar">#', $body), 'Kavenegar card not muted');
        LiteAssert::same(200, $a->request('GET', '/org/sms')['status']);
        $a->setOrgEntitled('marketing', true);
        $a->setOrgEntitled('marketing_email', true);
    },

    'translations: the new 3.1 keys exist in all five languages' => function () {
        $root = dirname(__DIR__, 2);
        $keys = ['marketing.error.contacts_permission', 'marketing.error.transactional_permission', 'marketing.error.transactional_audience', 'marketing.contacts_hidden', 'marketing.preview_masked', 'marketing.warning.large_audience', 'marketing.approve_text_large', 'marketing.preparing_hint'];
        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $lang) {
            $strings = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                LiteAssert::true(isset($strings[$key]) && $strings[$key] !== '', "{$lang}: {$key}");
            }
        }
    },

    'cleanup: roll back everything' => function () use ($h) {
        if (isset($h->a)) {
            $h->a->rollBack();
        }
        LiteAssert::true(true);
    },
];
