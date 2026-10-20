<?php

declare(strict_types=1);

/**
 * Phase E — Customer Satisfaction test invitation: an administrator sends
 * a survey invitation to their own mobile / email through the real flow
 * (single-use public link, Marketing delivery). crm_local only, one
 * transaction, rolled back. SMS goes to FakeSmsSender; nothing is sent.
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\SmsService;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use Modules\CustomerSatisfaction\Services\InvitationService;
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
        $h->a->pdo->query('SELECT is_test FROM cx_invitations LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Customer Satisfaction 0003 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    $pdo->exec("DELETE FROM modules WHERE `key` IN ('marketing', 'marketing_email', 'customer_satisfaction')");
    foreach (['marketing', 'customer_satisfaction'] as $key) {
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $key]);
        $h->moduleIds[$key] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, $h->moduleIds[$key]]);
    }
    $pdo->exec('DELETE FROM rate_limit_hits');
    $c->make(OrganizationEntitlements::class)->reset();

    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);

    // The admin's own mobile (profile).
    $pdo->prepare("UPDATE users SET mobile_phone = '0912 888 0001' WHERE id = ?")->execute([(int) $a->admin['id']]);
    $a->admin['mobile_phone'] = '0912 888 0001';

    // An INACTIVE survey (tests may try a survey before it is switched on).
    $pdo->prepare("INSERT INTO cx_surveys (organization_id, name, type, status, trigger_event, send_delay_minutes, link_valid_days, channel) VALUES (?, 'Lite test survey', 'csat', 'inactive', 'order.completed', 0, 3, 'sms')")->execute([$a->orgId]);
    $h->surveyId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cx_survey_questions (organization_id, survey_id, position, kind, label, scale_min, scale_max, required, active) VALUES (?, ?, 1, 'score', 'How satisfied are you?', 1, 5, 1, 1)")->execute([$a->orgId, $h->surveyId]);
    $h->questionId = (int) $pdo->lastInsertId();
    $h->ready = true;
};

$count = fn (string $sql) => (int) $h->a->pdo->query($sql)->fetchColumn();
$send = function (array $body, ?array $user = null) use ($h): array {
    $h->a->as($user ?? $h->a->admin);

    return $h->a->request('POST', '/cx/test', [], $body);
};

return [
    'form: offered to cx.manage users with the ready channels and their own addresses' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $body = $h->a->request('GET', '/cx')['body'];
        LiteAssert::contains('id="test-invitation"', $body);
        LiteAssert::contains('action="/cx/test"', $body);
        LiteAssert::contains('09128880001', $body);
        LiteAssert::contains('Lite test survey', $body);
    },

    'sends a real invitation to the admin\'s own mobile: marked test, "[Test]" text, single-use link, no order / contact' => function () use ($setup, $h, $send, $count) {
        $setup();
        $orders = $count('SELECT COUNT(*) FROM woo_orders');
        $contacts = $count('SELECT COUNT(*) FROM contacts');
        $res = $send(['survey_id' => (string) $h->surveyId, 'channel' => 'sms']);
        LiteAssert::same(302, $res['status']);
        LiteAssert::same(1, count($h->sms->sent));
        $sms = $h->sms->sent[0];
        LiteAssert::same('09128880001', $sms['receptor']);
        LiteAssert::true(str_starts_with($sms['message'], '[Test]'));
        LiteAssert::same(1, preg_match('#/p/([A-Za-z0-9_-]{43})#', $sms['message'], $m));
        $h->token = $m[1];

        $inv = $h->a->pdo->query("SELECT * FROM cx_invitations WHERE survey_id = {$h->surveyId} ORDER BY id DESC LIMIT 1")->fetch();
        LiteAssert::same('1', (string) $inv['is_test']);
        LiteAssert::same('sent', $inv['status']);
        LiteAssert::same(null, $inv['contact_id']);
        LiteAssert::same('test', $inv['source']);
        $h->invitationId = (int) $inv['id'];
        LiteAssert::same($orders, $count('SELECT COUNT(*) FROM woo_orders'));
        LiteAssert::same($contacts, $count('SELECT COUNT(*) FROM contacts'));
        LiteAssert::same('1', (string) $h->a->pdo->query('SELECT max_uses FROM public_links WHERE id = ' . (int) $inv['public_link_id'])->fetchColumn());
        LiteAssert::same('cx_survey_test', $h->a->pdo->query("SELECT purpose FROM marketing_transactional_messages WHERE reference = 'cx_invitation:{$h->invitationId}'")->fetchColumn());

        $page = $h->a->request('GET', '/cx')['body'];
        LiteAssert::contains('Test invitation sent', $page);
        LiteAssert::contains('>Test<', $page, 'shown as a test in the invitations list');
    },

    'answering a test: the real thank-you page, the link is used up, but no survey response is stored' => function () use ($setup, $h, $count) {
        $setup();
        $responses = $count("SELECT COUNT(*) FROM cx_responses WHERE survey_id = {$h->surveyId}");
        LiteAssert::contains('How satisfied are you?', $h->a->request('GET', '/p/' . $h->token)['body']);
        $res = $h->a->request('POST', '/p/' . $h->token, [], ['q' . $h->questionId => '1']);
        LiteAssert::same(200, $res['status']);
        LiteAssert::same($responses, $count("SELECT COUNT(*) FROM cx_responses WHERE survey_id = {$h->surveyId}"), 'statistics and follow-ups stay clean');
        LiteAssert::same('responded', $h->a->pdo->query("SELECT status FROM cx_invitations WHERE id = {$h->invitationId}")->fetchColumn());
        LiteAssert::same(404, $h->a->request('GET', '/p/' . $h->token)['status'], 'single use');
    },

    'not sent when the profile has no mobile, or the channel is not ready; nothing created' => function () use ($setup, $h, $send, $count) {
        $setup();
        $before = $count("SELECT COUNT(*) FROM cx_invitations WHERE survey_id = {$h->surveyId}");
        $noMobile = array_merge($h->a->admin, ['mobile_phone' => '']);
        $send(['survey_id' => (string) $h->surveyId, 'channel' => 'sms'], $noMobile);
        LiteAssert::contains('no valid Iranian mobile', $h->a->request('GET', '/cx')['body']);
        $send(['survey_id' => (string) $h->surveyId, 'channel' => 'email']);
        LiteAssert::contains('No delivery channel is ready', $h->a->request('GET', '/cx')['body']);
        LiteAssert::same($before, $count("SELECT COUNT(*) FROM cx_invitations WHERE survey_id = {$h->surveyId}"));
        LiteAssert::same(1, count($h->sms->sent));
    },

    'delivery failure: reported, marked failed, never retried in the background' => function () use ($setup, $h, $send) {
        $setup();
        $h->sms->script[] = SmsResult::failure('Could not reach the SMS provider.');
        $send(['survey_id' => (string) $h->surveyId, 'channel' => 'sms']);
        $inv = $h->a->pdo->query("SELECT * FROM cx_invitations WHERE survey_id = {$h->surveyId} ORDER BY id DESC LIMIT 1")->fetch();
        LiteAssert::same('failed', $inv['status']);
        LiteAssert::contains('was not sent', $h->a->request('GET', '/cx')['body']);
        $h->a->pdo->exec("UPDATE cx_invitations SET send_after = '2000-01-01 00:00:00' WHERE id = {$inv['id']}");
        $sent = count($h->sms->sent);
        $h->a->container()->make(InvitationService::class)->dispatchDue(5);
        LiteAssert::same($sent, count($h->sms->sent));
    },

    'real invitations unchanged: an inactive survey still never sends a real invitation' => function () use ($setup, $h) {
        $setup();
        $h->a->pdo->prepare("INSERT INTO cx_invitations (organization_id, survey_id, source, context_type, context_ref, recipient_phone, status, send_after) VALUES (?, ?, 'woocommerce', 'order', 'lite-real-1', '09128880002', 'scheduled', '2000-01-01 00:00:00')")
            ->execute([$h->a->orgId, $h->surveyId]);
        $sent = count($h->sms->sent);
        $h->a->container()->make(InvitationService::class)->dispatchDue(5);
        LiteAssert::same($sent, count($h->sms->sent));
        LiteAssert::same('cancelled', $h->a->pdo->query("SELECT status FROM cx_invitations WHERE context_ref = 'lite-real-1'")->fetchColumn());
    },

    'access: cx.manage needed; another organization\'s survey is refused; module off → no access' => function () use ($setup, $h, $send, $count) {
        $setup();
        $h->a->as($h->a->noRole);
        LiteAssert::same('forbidden', $h->a->outcome('POST', '/cx/test', [], ['survey_id' => (string) $h->surveyId, 'channel' => 'sms']));
        if ($h->a->member !== null) {
            $h->a->as($h->a->member); // system member: cx.view only
            LiteAssert::same('forbidden', $h->a->outcome('POST', '/cx/test', [], ['survey_id' => (string) $h->surveyId, 'channel' => 'sms']));
            LiteAssert::notContains('id="test-invitation"', $h->a->request('GET', '/cx')['body']);
        }
        if ($h->a->otherOrgId !== null) {
            $h->a->pdo->prepare("INSERT INTO cx_surveys (organization_id, name, status) VALUES (?, 'Foreign survey', 'active')")->execute([$h->a->otherOrgId]);
            $foreign = (int) $h->a->pdo->lastInsertId();
            $sent = count($h->sms->sent);
            $send(['survey_id' => (string) $foreign, 'channel' => 'sms']);
            LiteAssert::same($sent, count($h->sms->sent));
            LiteAssert::same(0, $count("SELECT COUNT(*) FROM cx_invitations WHERE survey_id = {$foreign}"));
        }
        $h->a->setOrgEntitled('customer_satisfaction', false);
        $h->a->as($h->a->admin);
        LiteAssert::true(in_array($h->a->outcome('POST', '/cx/test', [], ['survey_id' => (string) $h->surveyId, 'channel' => 'sms']), ['forbidden', 'notfound', 'status:403'], true));
        $h->a->setOrgEntitled('customer_satisfaction', true);
        $h->a->container()->make(OrganizationEntitlements::class)->reset();
    },

    'rate limit: at most 10 test invitations per user per hour' => function () use ($setup, $h, $send, $count) {
        $setup();
        $h->a->pdo->exec('DELETE FROM rate_limit_hits');
        for ($i = 0; $i < 10; $i++) {
            $send(['survey_id' => (string) $h->surveyId, 'channel' => 'sms']);
        }
        $sent = count($h->sms->sent);
        $send(['survey_id' => (string) $h->surveyId, 'channel' => 'sms']);
        LiteAssert::same($sent, count($h->sms->sent));
        LiteAssert::contains('Too many test invitations', $h->a->request('GET', '/cx')['body']);
    },

    'fa page renders without raw keys' => function () use ($setup, $h) {
        $setup();
        $h->a->as($h->a->admin);
        $h->a->locale('fa');
        LiteAssert::notContains('cx.test.', $h->a->request('GET', '/cx')['body']);
        $h->a->locale('en');
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM cx_surveys WHERE name = 'Lite test survey'")->fetchColumn());
    },
];
