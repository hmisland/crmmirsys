<?php

declare(strict_types=1);

/**
 * Phase D — scheduled Marketing campaigns: validation in the
 * organization's time zone, nothing sent before the time, exactly once
 * after it (repeated / concurrent worker runs), cancellation, background
 * preparation of large audiences, retry behavior, opt-outs at send time,
 * UI. crm_local only, one transaction, rolled back. SMS goes to
 * FakeSmsSender; nothing is sent.
 *
 * Tests run in order and share fixtures.
 */

use App\Application\Services\SmsService;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Sms\SmsProviderDefinition;
use App\Infrastructure\Sms\SmsProviderRegistry;
use App\Infrastructure\Sms\SmsResult;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\QueueWorker;
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
        $h->a->pdo->query('SELECT scheduled_at FROM marketing_campaigns LIMIT 1');
    } catch (\Throwable $e) {
        $h->reason = 'local database unavailable or Marketing 0015 not migrated: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $a = $h->a;
    $a->locale('en');
    $pdo = $a->pdo;
    $c = $a->container();
    $pdo->exec("DELETE FROM modules WHERE `key` = 'marketing'");
    $pdo->exec("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES ('marketing', 'marketing', '1.0.0', 'addon', 0, 'active', '[]')");
    $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $pdo->lastInsertId()]);
    // A fixed zone for the conversion checks (Asia/Tehran: UTC+03:30, no daylight saving since 2022).
    $pdo->prepare("UPDATE organizations SET default_timezone = 'Asia/Tehran' WHERE id = ?")->execute([$a->orgId]);
    OrganizationTimezone::shared()->forget();

    $h->sms = new FakeSmsSender();
    $c->make(SmsProviderRegistry::class)->register(new SmsProviderDefinition(
        'fakesms', 'Fake SMS', ['en', 'fa', 'ar', 'tr', 'fr'], [],
        ['api_key' => ['label' => 'sms_field.api_key', 'secret' => true, 'required' => true]],
        fn (array $creds) => $h->sms,
    ));
    $c->make(SmsService::class)->saveOrganizationSettings($a->orgId, 'own', 'fakesms', ['api_key' => 'fake-key'], (int) $a->admin['id']);

    $pdo->prepare('INSERT INTO contact_categories (organization_id, name) VALUES (?, ?)')->execute([$a->orgId, 'LiteSched group']);
    $h->groupId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare("INSERT INTO contacts (organization_id, first_name, last_name, phone, category_id, status) VALUES (?, ?, 'LiteSched', ?, ?, 'active')");
    foreach (['Ali' => '09137770001', 'Bita' => '09137770002', 'Cyrus' => '09137770003'] as $name => $phone) {
        $insert->execute([$a->orgId, $name, $phone, $h->groupId]);
    }
    $h->campaigns = $c->make(CampaignService::class);
    $h->worker = $c->make(QueueWorker::class);
    $h->ready = true;
};

$draft = function (string $name) use ($h): int {
    $h->a->as($h->a->admin);
    $r = $h->a->request('POST', '/marketing/campaigns', [], ['name' => $name, 'channel' => 'sms', 'message_class' => 'marketing', 'body_text' => 'Hi {{first_name}}', 'audience_type' => 'group', 'group_id' => (string) $h->groupId]);
    if ($r['status'] !== 302 || preg_match('#^/marketing/campaigns/(\d+)$#', (string) $r['location'], $m) !== 1) {
        throw new LiteAssertionFailed('draft not created: HTTP ' . $r['status']);
    }

    return (int) $m[1];
};
/** Local date + time ($minutes from now) in the organization's zone. */
$localIn = function (int $minutes): array {
    $local = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->modify(sprintf('%+d minutes', $minutes));

    return [$local->format('Y-m-d'), $local->format('H:i')];
};
$run = fn (int $id) => \App\Infrastructure\Repositories\OrganizationContext::runAs($h->a->orgId, fn () => $h->worker->processCampaign($id, 100, microtime(true) + 20));
$campaign = fn (int $id) => $h->a->pdo->query("SELECT * FROM marketing_campaigns WHERE id = {$id}")->fetch();
$sentTo = fn () => array_column($h->sms->sent, 'receptor');

return [
    'validation: bad date, missing time, the past, more than a year ahead → refused, campaign stays a draft' => function () use ($setup, $h, $draft, $localIn, $campaign) {
        $setup();
        $id = $draft('LiteSched validation');
        $approve = fn (?string $date, ?string $time) => $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false, false, $date, $time);
        foreach ([['2026-02-30', '10:00', 'send_date'], ['tomorrow', '10:00', 'send_date'], [$localIn(120)[0], '', 'send_time'], [$localIn(120)[0], '25:00', 'send_time'],
                  [...$localIn(-5), 'send_date'], [...$localIn(400 * 1440), 'send_date']] as [$date, $time, $field]) {
            try {
                $approve($date, $time);
                throw new LiteAssertionFailed("accepted {$date} {$time}");
            } catch (ValidationException $e) {
                LiteAssert::true(isset($e->errors[$field]), "{$date} {$time}: error on {$field}");
            }
        }
        LiteAssert::same('draft', $campaign($id)['status']);
        LiteAssert::same(null, $campaign($id)['scheduled_at']);
    },

    'time zone: the local send time is stored as UTC (Tehran 10:00 = 06:30 UTC)' => function () use ($setup, $h, $draft, $campaign) {
        $setup();
        $id = $draft('LiteSched zone');
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tehran')))->modify('+2 days')->format('Y-m-d');
        LiteAssert::same(3, $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false, false, $date, '10:00'));
        LiteAssert::same($date . ' 06:30:00', $campaign($id)['scheduled_at']);
        LiteAssert::same('queued', $campaign($id)['status']);
        $h->zoneCampaign = $id;
    },

    'not before its time: worker runs (cron and page ticks) send nothing and do not start the campaign' => function () use ($setup, $h, $run, $campaign, $sentTo) {
        $setup();
        $before = count($h->sms->sent);
        for ($i = 0; $i < 3; $i++) {
            $result = $run($h->zoneCampaign);
            LiteAssert::same(0, $result['processed']);
        }
        $h->worker->runAll(5);
        LiteAssert::same($before, count($h->sms->sent));
        LiteAssert::same('queued', $campaign($h->zoneCampaign)['status']);
        LiteAssert::same(3, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$h->zoneCampaign} AND status = 'pending'")->fetchColumn());
    },

    'once due: sent exactly once to each recipient, even with repeated runs' => function () use ($setup, $h, $run, $campaign, $sentTo) {
        $setup();
        $h->a->pdo->exec("UPDATE marketing_campaigns SET scheduled_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$h->zoneCampaign}");
        $before = count($h->sms->sent);
        $run($h->zoneCampaign);
        $run($h->zoneCampaign);
        $h->worker->runAll(5);
        LiteAssert::same($before + 3, count($h->sms->sent));
        LiteAssert::same(3, count(array_unique(array_slice($sentTo(), $before))));
        LiteAssert::same('completed', $campaign($h->zoneCampaign)['status']);
    },

    'opt-out after approval: still honored at send time' => function () use ($setup, $h, $draft, $localIn, $run, $sentTo) {
        $setup();
        $id = $draft('LiteSched optout');
        $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false, false, ...$localIn(90));
        $h->a->pdo->prepare("INSERT INTO marketing_suppressions (organization_id, channel, address, reason, source) VALUES (?, 'sms', '09137770002', 'unsubscribed', 'link')")->execute([$h->a->orgId]);
        $h->a->pdo->exec("UPDATE marketing_campaigns SET scheduled_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::false(in_array('09137770002', array_slice($sentTo(), $before), true));
        LiteAssert::same('suppressed_unsubscribed', $h->a->pdo->query("SELECT skip_reason FROM marketing_messages WHERE campaign_id = {$id} AND address = '09137770002'")->fetchColumn());
        $h->a->pdo->exec("DELETE FROM marketing_suppressions WHERE organization_id = {$h->a->orgId} AND address = '09137770002'");
    },

    'cancel before the time: queued messages cancelled, nothing ever sent' => function () use ($setup, $h, $draft, $localIn, $run, $campaign) {
        $setup();
        $id = $draft('LiteSched cancel');
        $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false, false, ...$localIn(60));
        $h->a->as($h->a->admin);
        LiteAssert::same(302, $h->a->request('POST', "/marketing/campaigns/{$id}/cancel")['status']);
        LiteAssert::same('cancelled', $campaign($id)['status']);
        $h->a->pdo->exec("UPDATE marketing_campaigns SET scheduled_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::same($before, count($h->sms->sent));
        LiteAssert::same(3, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_messages WHERE campaign_id = {$id} AND status = 'cancelled'")->fetchColumn());
    },

    'large audience: prepared in the background ahead of time, sent only once due' => function () use ($setup, $h, $draft, $localIn, $campaign) {
        $setup();
        // A copy of the service with a lower "large audience" limit (promoted properties = parameter names).
        $class = new \ReflectionClass(CampaignService::class);
        $service = $class->newInstanceArgs(array_map(
            fn (\ReflectionParameter $p) => $p->getName() === 'syncAudienceLimit' ? 1 : (new \ReflectionProperty($h->campaigns, $p->getName()))->getValue($h->campaigns),
            $class->getConstructor()->getParameters(),
        ));
        $id = $draft('LiteSched large');
        LiteAssert::same(3, $service->approveAndQueue($h->a->admin, $id, 3, false, false, ...$localIn(30)));
        LiteAssert::same('preparing', $campaign($id)['status']);
        $before = count($h->sms->sent);
        $h->worker->runAll(5);
        LiteAssert::same('queued', $campaign($id)['status'], 'recipients prepared');
        LiteAssert::same($before, count($h->sms->sent), 'nothing sent yet');
        $h->a->pdo->exec("UPDATE marketing_campaigns SET scheduled_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $h->worker->runAll(5);
        LiteAssert::same($before + 3, count($h->sms->sent));
    },

    'failure after the time: retryable errors go back to the queue as before; final errors fail' => function () use ($setup, $h, $draft, $localIn, $run) {
        $setup();
        $id = $draft('LiteSched retry');
        $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false, false, ...$localIn(5));
        $h->a->pdo->exec("UPDATE marketing_campaigns SET scheduled_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $h->sms->script = [SmsResult::failure('Could not reach the SMS provider.'), new SmsResult(false, 'Invalid line', 412, [])];
        $run($id);
        $rows = $h->a->pdo->query("SELECT status, attempts, next_attempt_at FROM marketing_messages WHERE campaign_id = {$id} ORDER BY id")->fetchAll();
        LiteAssert::same('pending', $rows[0]['status']);
        LiteAssert::true($rows[0]['next_attempt_at'] !== null);
        LiteAssert::same('failed', $rows[1]['status']);
        LiteAssert::same('sent', $rows[2]['status']);
        $h->sms->script = [];
    },

    'immediate approval unchanged: without a send time the campaign starts at once' => function () use ($setup, $h, $draft, $run, $campaign) {
        $setup();
        $id = $draft('LiteSched now');
        $h->campaigns->approveAndQueue($h->a->admin, $id, 3, false);
        LiteAssert::same(null, $campaign($id)['scheduled_at']);
        $before = count($h->sms->sent);
        $run($id);
        LiteAssert::same($before + 3, count($h->sms->sent));
    },

    'UI: approval form offers date + time; a scheduled campaign shows "Scheduled" with the local time; fa has no raw keys' => function () use ($setup, $h, $draft, $localIn) {
        $setup();
        $h->a->as($h->a->admin);
        $id = $draft('LiteSched ui');
        $form = $h->a->request('GET', "/marketing/campaigns/{$id}")['body'];
        LiteAssert::contains('name="send_date"', $form);
        LiteAssert::contains('class="crm-datepicker"', $form);
        LiteAssert::contains('name="send_time"', $form);
        [$date, $time] = $localIn(180);
        $res = $h->a->request('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => '3', 'send_date' => $date, 'send_time' => $time]);
        LiteAssert::same(302, $res['status']);
        $page = $h->a->request('GET', "/marketing/campaigns/{$id}")['body'];
        LiteAssert::contains('Scheduled', $page);
        LiteAssert::contains($date . ' ' . $time, $page, 'shown in the organization\'s local time');
        LiteAssert::contains('Scheduled', $h->a->request('GET', '/marketing/campaigns')['body']);
        $h->a->locale('fa');
        LiteAssert::notContains('marketing.schedule', $h->a->request('GET', "/marketing/campaigns/{$id}")['body']);
        $h->a->locale('en');
        $bad = $h->a->request('POST', "/marketing/campaigns/{$draft('LiteSched ui2')}/send", [], ['confirmed_count' => '3', 'send_date' => $date, 'send_time' => '']);
        LiteAssert::same(422, $bad['status']);
        LiteAssert::contains('Enter the send time', $bad['body']);
    },

    'approval still needs marketing.send' => function () use ($setup, $h, $draft, $localIn) {
        $setup();
        $id = $draft('LiteSched perm');
        $h->a->as($h->a->noRole);
        LiteAssert::true(in_array($h->a->outcome('POST', "/marketing/campaigns/{$id}/send", [], ['confirmed_count' => '3', 'send_date' => $localIn(60)[0], 'send_time' => $localIn(60)[1]]), ['forbidden', 'notfound'], true));
        $h->a->as($h->a->admin);
    },

    'rollback leaves no test rows behind' => function () use ($setup, $h) {
        $setup();
        $h->a->rollBack();
        OrganizationTimezone::shared()->forget();
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM marketing_campaigns WHERE name LIKE 'LiteSched%'")->fetchColumn());
    },
];
