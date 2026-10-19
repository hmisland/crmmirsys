<?php

declare(strict_types=1);

/**
 * Integration framework + WooCommerce + public links + Customer
 * Satisfaction, end to end through the real container, router,
 * middleware and database (crm_local only, one transaction, rolled back
 * at the end). WooCommerce is ALWAYS mocked (FakeWooHttp): no real store
 * is called. Delivery is a recording fake of Core's
 * TransactionalDeliveryInterface: nothing is sent.
 *
 * Tests run in order and share fixtures.
 */

use App\Core\Exceptions\NotFoundException;
use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Integrations\IntegrationConnectionRepository;
use App\Infrastructure\Integrations\IntegrationJobRepository;
use App\Infrastructure\Integrations\IntegrationRegistry;
use App\Infrastructure\Integrations\IntegrationService;
use App\Infrastructure\Integrations\IntegrationWebhookReceiver;
use App\Infrastructure\Integrations\IntegrationWorker;
use App\Infrastructure\Messaging\DeliveryReceipt;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Messaging\TransactionalMessage;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\CustomerSatisfaction\Services\InvitationService;
use Modules\CustomerSatisfaction\Services\SurveyScale;
use Modules\CustomerSatisfaction\Services\SurveyService;
use Modules\WooCommerce\Api\StoreUrl;
use Modules\WooCommerce\Api\WooCommerceClient;
use Modules\WooCommerce\Services\WooOrderImporter;
use Modules\WooCommerce\WooCommerceProvider;
use Modules\WooCommerce\WooDataRepository;
use Tests\Lite\Support\LocalApp;

require_once __DIR__ . '/Support/LocalApp.php';

final class FakeWooHttp implements HttpClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array}> */
    public array $requests = [];
    /** @var array<int, HttpResponse> queued responses; when empty: an empty order list */
    public array $queue = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

        return array_shift($this->queue) ?? new HttpResponse(200, '[]', ['x-wp-totalpages' => '1']);
    }

    public function respond(array $items, int $status = 200): void
    {
        $this->queue[] = new HttpResponse($status, (string) json_encode($items), ['x-wp-totalpages' => '1']);
    }
}

final class RecordingDelivery implements TransactionalDeliveryInterface
{
    /** @var TransactionalMessage[] */
    public array $sent = [];
    public array $channels = ['sms', 'email'];
    public string $mode = DeliveryReceipt::ACCEPTED;

    public function availableChannels(int $organizationId): array
    {
        return $this->channels;
    }

    public function deliver(TransactionalMessage $message): DeliveryReceipt
    {
        $this->sent[] = $message;

        return new DeliveryReceipt($this->mode, $this->mode === DeliveryReceipt::ACCEPTED ? 'fake-' . count($this->sent) : null, $this->mode === DeliveryReceipt::ACCEPTED ? null : 'fake.error');
    }
}

$h = new \stdClass();
$h->ready = false;
$h->reason = '';
$h->stamp = gmdate('Y-m-d\TH:i:s', time() - 3600);

/** A WooCommerce order JSON object (shape of /wc/v3/orders). */
$order = function (int $id, string $status, string $modified, array $extra = []) use ($h): array {
    return array_replace([
        'id' => $id,
        'number' => (string) (5000 + $id),
        'status' => $status,
        'currency' => 'IRR',
        'total' => '1250000.00',
        'customer_id' => 0,
        'date_created_gmt' => $h->stamp,
        'date_modified_gmt' => $modified,
        'date_completed_gmt' => $status === 'completed' ? $modified : null,
        'billing' => ['first_name' => 'Sara', 'last_name' => 'Ahmadi', 'email' => 'lite.woo.' . $id . '@example.test', 'phone' => '09120000' . str_pad((string) $id, 3, '0', STR_PAD_LEFT)],
        'line_items' => [
            ['id' => $id * 10 + 1, 'product_id' => 77, 'variation_id' => 0, 'name' => 'Lite product', 'sku' => 'LITE-77', 'quantity' => 2, 'total' => '1000000.00'],
            ['id' => $id * 10 + 2, 'product_id' => 78, 'variation_id' => 0, 'name' => 'Lite extra', 'sku' => null, 'quantity' => 1, 'total' => '250000.00'],
        ],
    ], $extra);
};

$count = fn (string $sql, array $params = []) => (function () use ($h, $sql, $params): int {
    $stmt = $h->a->pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
})();

$setup = function () use ($h): void {
    if ($h->ready) {
        return;
    }
    if ($h->reason !== '') {
        throw new LiteSkip($h->reason);
    }
    try {
        $h->a = LocalApp::boot();
    } catch (\Throwable $e) {
        $h->reason = 'local app unavailable: ' . $e->getMessage();
        throw new LiteSkip($h->reason);
    }
    $pdo = $h->a->pdo;
    if ($pdo->query("SHOW TABLES LIKE 'integration_connections'")->fetchColumn() === false || $pdo->query("SHOW TABLES LIKE 'cx_surveys'")->fetchColumn() === false) {
        $h->reason = 'migrations 0085/0086 + module migrations not applied on crm_local';
        throw new LiteSkip($h->reason);
    }
    // Install + entitle both modules for the test organization (inside the rolled-back transaction).
    foreach (['woocommerce' => 'WooCommerce integration', 'customer_satisfaction' => 'Customer Satisfaction'] as $key => $name) {
        $pdo->prepare('DELETE FROM modules WHERE `key` = ?')->execute([$key]);
        $pdo->prepare("INSERT INTO modules (`key`, name, version, type, is_core, status, dependencies) VALUES (?, ?, '1.0.0', 'addon', 0, 'active', '[]')")->execute([$key, $name]);
        $pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$h->a->orgId, (int) $pdo->lastInsertId()]);
    }

    $c = $h->a->container();
    $c->make(OrganizationEntitlements::class)->reset();
    // Swap the real WooCommerce HTTP transport for the fake (same provider class, same importer).
    $h->http = new FakeWooHttp();
    $h->provider = new WooCommerceProvider(new WooCommerceClient($h->http), $c->make(WooOrderImporter::class), ['per_page' => 2, 'pages_per_job' => 1, 'initial_days' => 30, 'sync_interval_minutes' => 15]);
    $c->make(IntegrationRegistry::class)->register($h->provider);
    $h->integrations = $c->make(IntegrationService::class);
    $h->connections = $c->make(IntegrationConnectionRepository::class);
    $h->jobs = $c->make(IntegrationJobRepository::class);
    $h->worker = $c->make(IntegrationWorker::class);
    $h->receiver = $c->make(IntegrationWebhookReceiver::class);
    $h->links = $c->make(PublicLinkService::class);
    $h->invitations = $c->make(InvitationService::class);
    $h->ready = true;
};

/** Runs the worker until the queue is empty (bounded). */
$drain = function () use ($h): array {
    $total = ['processed' => 0, 'failed' => 0];
    for ($i = 0; $i < 10; $i++) {
        $h->a->container()->make(OrganizationEntitlements::class)->reset();
        $stats = $h->worker->run(20);
        $total['processed'] += $stats['processed'];
        $total['failed'] += $stats['failed'];
        if ($stats['processed'] + $stats['failed'] === 0) {
            break;
        }
    }

    return $total;
};

$signed = fn (string $body, string $secret, string $topic) => [
    'X-WC-WEBHOOK-SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
    'X-WC-WEBHOOK-TOPIC' => $topic,
    'X-WC-WEBHOOK-DELIVERY-ID' => (string) random_int(1, 99999),
];

return [
    'store URL validation: https, no credentials/query; normalized without trailing slash' => function () use ($setup) {
        $setup();
        LiteAssert::same('https://shop.example.com', StoreUrl::normalize('https://Shop.Example.com/'));
        LiteAssert::same('https://shop.example.com/store', StoreUrl::normalize('https://shop.example.com/store/'));
        LiteAssert::same(null, StoreUrl::normalize('https://user:pass@shop.example.com'));
        LiteAssert::same(null, StoreUrl::normalize('https://shop.example.com/?consumer_key=x'));
        LiteAssert::same(null, StoreUrl::normalize('ftp://shop.example.com'));
        LiteAssert::same(null, StoreUrl::normalize('not a url'));
    },

    'create connection: validation errors, then secrets stored encrypted and never in the view' => function () use ($setup, $h) {
        $setup();
        $bad = $h->integrations->create($h->a->admin, 'woocommerce', ['name' => 'Shop', 'store_url' => 'https://shop.example.com', 'consumer_key' => 'nope', 'consumer_secret' => 'cs_' . str_repeat('b', 40)]);
        LiteAssert::same(null, $bad['id']);
        LiteAssert::true(isset($bad['errors']['consumer_key']));

        $h->ck = 'ck_' . str_repeat('a1', 20);
        $h->cs = 'cs_' . str_repeat('b2', 20);
        $ok = $h->integrations->create($h->a->admin, 'woocommerce', ['name' => 'Lite shop', 'store_url' => 'https://shop.example.com/', 'consumer_key' => $h->ck, 'consumer_secret' => $h->cs]);
        LiteAssert::same([], $ok['errors']);
        $h->connId = (int) $ok['id'];

        $row = $h->a->pdo->query('SELECT * FROM integration_connections WHERE id = ' . $h->connId)->fetch();
        LiteAssert::same($h->a->orgId, (int) $row['organization_id']);
        LiteAssert::true(str_starts_with((string) $row['credentials_encrypted'], 'v1:'));
        LiteAssert::notContains($h->ck, (string) $row['credentials_encrypted']);
        LiteAssert::notContains($h->cs, (string) $row['credentials_encrypted']);
        LiteAssert::same(1, preg_match('/^[a-f0-9]{40}$/', (string) $row['webhook_key']));
        LiteAssert::same('{"store_url":"https:\/\/shop.example.com"}', json_encode(json_decode((string) $row['config_json'], true)));

        $view = json_encode($h->integrations->overview($h->a->admin)['woocommerce']['connections']);
        LiteAssert::notContains($h->ck, (string) $view);
        LiteAssert::notContains($h->cs, (string) $view);
        LiteAssert::notContains('credentials_encrypted', (string) $view);
        LiteAssert::contains('…', (string) json_decode((string) $view, true)[0]['credentials_hint']);
    },

    'settings pages: list never prints keys or webhook secret; webhook page shows URL + secret, no-store' => function () use ($setup, $h) {
        $setup();
        $h->a->locale('en');
        $page = $h->a->request('GET', '/org/integrations');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('Lite shop', $page['body']);
        LiteAssert::notContains($h->ck, $page['body']);
        LiteAssert::notContains($h->cs, $page['body']);
        $secret = (string) $h->connections->findForJob($h->connId, $h->a->orgId)->webhookSecret();
        LiteAssert::true(strlen($secret) >= 32);
        LiteAssert::notContains($secret, $page['body']);

        $webhook = $h->a->request('GET', '/org/integrations/' . $h->connId . '/webhook');
        LiteAssert::same(200, $webhook['status']);
        LiteAssert::contains($secret, $webhook['body']);
        LiteAssert::contains('/webhooks/integrations/', $webhook['body']);
        LiteAssert::notContains($h->cs, $webhook['body']);

        $settings = $h->a->request('GET', '/org/settings');
        LiteAssert::contains('/org/integrations', $settings['body']);
    },

    'organization isolation: another organization cannot see or touch the connection' => function () use ($setup, $h) {
        $setup();
        if ($h->a->otherOrgId === null) {
            throw new LiteSkip('only one organization in crm_local');
        }
        OrganizationContext::runAs($h->a->otherOrgId, function () use ($h) {
            LiteAssert::same(null, $h->connections->find($h->connId));
            LiteAssert::same([], $h->connections->forOrganization('woocommerce'));
            LiteAssert::throws(NotFoundException::class, fn () => $h->integrations->requestSync($h->a->admin, $h->connId));
        });
    },

    'entitlement: without the woocommerce module every action is refused' => function () use ($setup, $h) {
        $setup();
        $h->a->setOrgEntitled('woocommerce', false);
        try {
            LiteAssert::throws(\DomainException::class, fn () => $h->integrations->requestSync($h->a->admin, $h->connId));
            LiteAssert::false($h->integrations->overview($h->a->admin)['woocommerce']['entitled']);
        } finally {
            $h->a->setOrgEntitled('woocommerce', true);
            $h->a->container()->make(\App\Infrastructure\Modules\ModuleGate::class)->reset();
        }
    },

    'connection test: credentials go in the Authorization header, never the URL' => function () use ($setup, $h) {
        $setup();
        $h->http->respond([]);
        $result = $h->integrations->test($h->a->admin, $h->connId);
        LiteAssert::true($result->ok);
        $request = end($h->http->requests);
        LiteAssert::contains('https://shop.example.com/wp-json/wc/v3/orders', $request['url']);
        LiteAssert::notContains('ck_', $request['url']);
        LiteAssert::notContains('cs_', $request['url']);
        LiteAssert::same('Basic ' . base64_encode($h->ck . ':' . $h->cs), $request['headers']['Authorization']);

        $h->http->respond(['code' => 'woocommerce_rest_cannot_view'], 401);
        $failed = $h->integrations->test($h->a->admin, $h->connId);
        LiteAssert::same('woocommerce.error.auth', $failed->errorKey);
    },

    'manual sync: queued once (a second click does not duplicate), no API call in the request' => function () use ($setup, $h, $count) {
        $setup();
        $calls = count($h->http->requests);
        LiteAssert::same(1, $h->integrations->requestSync($h->a->admin, $h->connId));
        LiteAssert::same(0, $h->integrations->requestSync($h->a->admin, $h->connId));
        LiteAssert::same($calls, count($h->http->requests));
        LiteAssert::same(1, $count("SELECT COUNT(*) FROM integration_jobs WHERE connection_id = ? AND status = 'pending'", [$h->connId]));
    },

    'cron sync: incremental pages, continuation job, idempotent rows, cursor saved' => function () use ($setup, $h, $order, $drain, $count) {
        $setup();
        $t1 = gmdate('Y-m-d\TH:i:s', time() - 7200);
        $t2 = gmdate('Y-m-d\TH:i:s', time() - 7100);
        $t3 = gmdate('Y-m-d\TH:i:s', time() - 7000);
        // per_page = 2: a full first page → continuation job; second page has 1 order → caught up.
        $h->http->respond([$order(901, 'processing', $t1), $order(902, 'processing', $t2)]);
        $h->http->respond([$order(902, 'processing', $t2), $order(903, 'on-hold', $t3)]);
        $h->http->respond([$order(903, 'on-hold', $t3)]);
        $before = count($h->http->requests);
        $stats = $drain();
        LiteAssert::same(3, count($h->http->requests) - $before, 'three API pages');
        LiteAssert::same(0, $stats['failed']);

        LiteAssert::same(3, $count('SELECT COUNT(*) FROM woo_orders WHERE connection_id = ?', [$h->connId]));
        LiteAssert::same(6, $count('SELECT COUNT(*) FROM woo_order_items i JOIN woo_orders o ON o.id = i.order_id WHERE o.connection_id = ?', [$h->connId]));
        LiteAssert::same(2, $count('SELECT COUNT(*) FROM woo_products WHERE connection_id = ?', [$h->connId]));
        LiteAssert::same(3, $count('SELECT COUNT(*) FROM woo_customers WHERE connection_id = ?', [$h->connId]));
        LiteAssert::same($h->a->orgId, $count('SELECT DISTINCT organization_id FROM woo_orders WHERE connection_id = ?', [$h->connId]));

        $requests = array_slice($h->http->requests, $before);
        LiteAssert::contains('orderby=modified', $requests[0]['url']);
        LiteAssert::contains('dates_are_gmt=true', $requests[0]['url']);
        LiteAssert::contains('modified_after=' . urlencode(gmdate('Y-m-d\TH:i:s', strtotime($t2 . ' UTC') - 1)), $requests[1]['url'], 'keyset cursor moved');

        $conn = $h->connections->findForJob($h->connId, $h->a->orgId);
        LiteAssert::same(gmdate('Y-m-d\TH:i:s', strtotime($t3 . ' UTC') - 1), $conn->syncState()['orders']['after']);
        LiteAssert::true($conn->toView()['last_successful_sync_at'] !== null);
        LiteAssert::same('connected', $conn->status());

        // Same data again (overlap / replay): no duplicates.
        $h->integrations->requestSync($h->a->admin, $h->connId);
        $h->http->respond([$order(903, 'on-hold', $t3)]);
        $drain();
        LiteAssert::same(3, $count('SELECT COUNT(*) FROM woo_orders WHERE connection_id = ?', [$h->connId]));
        LiteAssert::same(6, $count('SELECT COUNT(*) FROM woo_order_items i JOIN woo_orders o ON o.id = i.order_id WHERE o.connection_id = ?', [$h->connId]));
    },

    'scheduler: a connection synced recently is not rescheduled; a stale one is, once' => function () use ($setup, $h, $count) {
        $setup();
        LiteAssert::same(0, $h->worker->scheduleDueSyncs());
        $h->a->pdo->prepare('UPDATE integration_connections SET last_attempted_sync_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), $h->connId]);
        LiteAssert::true($h->worker->scheduleDueSyncs() >= 1);
        LiteAssert::same(0, $h->worker->scheduleDueSyncs(), 'not twice');
        LiteAssert::same(1, $count("SELECT COUNT(*) FROM integration_jobs WHERE connection_id = ? AND job_type = 'sync' AND status = 'pending'", [$h->connId]));
    },

    'retry: a temporary API failure is retried with back-off, auth failure is permanent' => function () use ($setup, $h) {
        $setup();
        $h->http->respond([], 503);
        $h->worker->run(5);
        $job = $h->a->pdo->query("SELECT * FROM integration_jobs WHERE connection_id = {$h->connId} AND job_type = 'sync' ORDER BY id DESC LIMIT 1")->fetch();
        LiteAssert::same('pending', $job['status']);
        LiteAssert::same('woocommerce.error.unreachable', $job['last_error']);
        LiteAssert::true(strtotime($job['available_at'] . ' UTC') > time(), 'back-off');
        LiteAssert::same('error', $h->connections->findForJob($h->connId, $h->a->orgId)->status());

        $h->a->pdo->exec("UPDATE integration_jobs SET available_at = '2000-01-01 00:00:00' WHERE id = " . (int) $job['id']);
        $h->http->respond([], 401);
        $h->worker->run(5);
        LiteAssert::same('failed', $h->a->pdo->query('SELECT status FROM integration_jobs WHERE id = ' . (int) $job['id'])->fetchColumn());
    },

    'webhook: bad signature 401, ping ignored, unknown key 404, valid queued once, replay is a duplicate' => function () use ($setup, $h, $order, $signed) {
        $setup();
        $conn = $h->connections->findForJob($h->connId, $h->a->orgId);
        $key = $conn->webhookKey();
        $secret = (string) $conn->webhookSecret();
        $h->a->pdo->exec("UPDATE integration_connections SET status = 'connected' WHERE id = {$h->connId}");
        $body = (string) json_encode($order(902, 'completed', gmdate('Y-m-d\TH:i:s', time() - 60)));

        LiteAssert::same(401, $h->receiver->receive($key, $signed($body, 'wrong-secret', 'order.updated'), $body)['status']);
        LiteAssert::same(401, $h->receiver->receive($key, ['X-WC-WEBHOOK-TOPIC' => 'order.updated'], $body)['status']);
        LiteAssert::same('ignored', $h->receiver->receive($key, [], 'webhook_id=12')['result']);
        LiteAssert::same(404, $h->receiver->receive(str_repeat('0', 40), $signed($body, $secret, 'order.updated'), $body)['status']);
        LiteAssert::same(404, $h->receiver->receive('../etc', [], $body)['status']);
        LiteAssert::same('ignored', $h->receiver->receive($key, $signed($body, $secret, 'product.updated'), $body)['result']);

        $calls = count($h->http->requests);
        LiteAssert::same(['status' => 200, 'result' => 'queued'], $h->receiver->receive($key, $signed($body, $secret, 'order.updated'), $body));
        LiteAssert::same(['status' => 200, 'result' => 'duplicate'], $h->receiver->receive($key, $signed($body, $secret, 'order.updated'), $body));
        LiteAssert::same($calls, count($h->http->requests), 'no API call while receiving');
        $h->webhookBody = $body;

        // The route answers JSON 404 for an unknown key (no login needed).
        $route = $h->a->request('POST', '/webhooks/integrations/' . str_repeat('f', 40), [], []);
        LiteAssert::same(404, $route['status']);
    },

    'CX survey: created through the service; non-managers are refused' => function () use ($setup, $h) {
        $setup();
        $surveys = $h->a->container()->make(SurveyService::class);
        $bad = $surveys->save($h->a->admin, null, ['name' => '', 'type' => 'xyz', 'question_label' => '']);
        LiteAssert::true(isset($bad['errors']['name'], $bad['errors']['type'], $bad['errors']['question_label']));
        $saved = $surveys->save($h->a->admin, null, [
            'name' => 'After purchase', 'type' => 'csat', 'status' => 'active', 'trigger_event' => 'order.completed',
            'send_delay_minutes' => '0', 'link_valid_days' => '14', 'channel' => 'sms',
            'question_label' => 'How satisfied are you?', 'ask_comment' => '1', 'comment_label' => '',
        ]);
        LiteAssert::same([], $saved['errors']);
        $h->surveyId = (int) $saved['id'];
        $survey = $surveys->find($h->surveyId);
        LiteAssert::same('How satisfied are you?', $survey['question_label']);
        LiteAssert::true($survey['ask_comment']);
        LiteAssert::throws(\App\Core\Exceptions\AuthorizationException::class, fn () => $surveys->save($h->a->noRole, null, ['name' => 'x']));
        LiteAssert::same([1, 5], SurveyScale::range('csat'));
        LiteAssert::same('detractor', SurveyScale::sentiment('nps', 6));
        LiteAssert::same('promoter', SurveyScale::sentiment('nps', 9));
    },

    'webhook job → order completed → one CX invitation (idempotent; old orders ignored)' => function () use ($setup, $h, $order, $drain, $count) {
        $setup();
        $drain();
        LiteAssert::same('completed', $h->a->pdo->query("SELECT status FROM woo_orders WHERE connection_id = {$h->connId} AND external_id = 902")->fetchColumn());
        LiteAssert::same(1, $count('SELECT COUNT(*) FROM cx_invitations WHERE survey_id = ?', [$h->surveyId]));
        $inv = $h->a->pdo->query("SELECT * FROM cx_invitations WHERE survey_id = {$h->surveyId}")->fetch();
        LiteAssert::same('woocommerce:' . $h->connId . ':902', $inv['context_ref']);
        LiteAssert::same('5902', $inv['context_label']);
        LiteAssert::same('scheduled', $inv['status']);

        // The same order completed again (e.g. via cron overlap) → no second invitation.
        $importer = $h->a->container()->make(WooOrderImporter::class);
        $importer->importOrder($h->connId, $order(902, 'processing', gmdate('Y-m-d\TH:i:s', time() - 30)));
        $importer->importOrder($h->connId, $order(902, 'completed', gmdate('Y-m-d\TH:i:s', time() - 20)));
        LiteAssert::same(1, $count('SELECT COUNT(*) FROM cx_invitations WHERE survey_id = ?', [$h->surveyId]));

        // An order completed 30 days ago (first seen during an initial import) does not trigger a survey.
        $old = gmdate('Y-m-d\TH:i:s', time() - 30 * 86400);
        $importer->importOrder($h->connId, $order(950, 'completed', $old));
        LiteAssert::same(1, $count('SELECT COUNT(*) FROM cx_invitations WHERE survey_id = ?', [$h->surveyId]));

        // An older payload never overwrites newer data.
        $importer->importOrder($h->connId, $order(902, 'processing', gmdate('Y-m-d\TH:i:s', time() - 5000)));
        LiteAssert::same('completed', $h->a->pdo->query("SELECT status FROM woo_orders WHERE connection_id = {$h->connId} AND external_id = 902")->fetchColumn());
    },

    'dispatch without a delivery channel: invitation waits, no link issued' => function () use ($setup, $h, $count) {
        $setup();
        $c = $h->a->container();
        LiteAssert::false($c->has(TransactionalDeliveryInterface::class), 'nothing bound yet in Phase 1');
        $links = $count("SELECT COUNT(*) FROM public_links WHERE purpose = 'cx_survey'");
        $stats = $h->invitations->dispatchDue(10);
        LiteAssert::true($stats['waiting'] >= 1);
        LiteAssert::same('waiting_channel', $h->a->pdo->query("SELECT status FROM cx_invitations WHERE survey_id = {$h->surveyId}")->fetchColumn());
        LiteAssert::same($links, $count("SELECT COUNT(*) FROM public_links WHERE purpose = 'cx_survey'"));
    },

    'dispatch through the delivery boundary: one message with a secure single-use link' => function () use ($setup, $h) {
        $setup();
        $c = $h->a->container();
        $h->delivery = new RecordingDelivery();
        $c->instance(TransactionalDeliveryInterface::class, $h->delivery);
        $h->a->pdo->exec("UPDATE cx_invitations SET send_after = '2000-01-01 00:00:00' WHERE survey_id = {$h->surveyId}");
        $stats = $h->invitations->dispatchDue(10);
        LiteAssert::same(1, $stats['sent']);
        LiteAssert::same(1, count($h->delivery->sent));
        $message = $h->delivery->sent[0];
        LiteAssert::same('sms', $message->channel);
        LiteAssert::same('09120000902', $message->address);
        LiteAssert::same($h->a->orgId, $message->organizationId);
        LiteAssert::same(1, preg_match('#/p/([A-Za-z0-9_-]{43})(\s|$)#', $message->text, $m));
        $h->token = $m[1];
        LiteAssert::notContains((string) $h->a->orgId . '.', $h->token);

        $inv = $h->a->pdo->query("SELECT * FROM cx_invitations WHERE survey_id = {$h->surveyId}")->fetch();
        LiteAssert::same('sent', $inv['status']);
        $link = $h->a->pdo->query('SELECT * FROM public_links WHERE id = ' . (int) $inv['public_link_id'])->fetch();
        LiteAssert::same(hash('sha256', $h->token), $link['token_hash']);
        LiteAssert::same('1', (string) $link['max_uses']);
        LiteAssert::true($link['expires_at'] !== null);
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM public_links WHERE token_hash = " . $h->a->pdo->quote($h->token))->fetchColumn(), 'plain token never stored');

        // Dispatching again sends nothing more.
        $h->invitations->dispatchDue(10);
        LiteAssert::same(1, count($h->delivery->sent));
    },

    'public survey page: no login, mobile layout, no internal data; answer once; link then unavailable' => function () use ($setup, $h, $count) {
        $setup();
        $page = $h->a->request('GET', '/p/' . $h->token);
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('How satisfied are you?', $page['body']);
        LiteAssert::contains('name="viewport"', $page['body']);
        LiteAssert::notContains('lite.woo.902@example.test', $page['body']);
        LiteAssert::notContains('5902', $page['body']);
        LiteAssert::notContains('09120000902', $page['body']);
        LiteAssert::notContains('sidebar', $page['body']);

        $q = $h->a->pdo->query("SELECT id, kind FROM cx_survey_questions WHERE survey_id = {$h->surveyId} AND active = 1 ORDER BY position")->fetchAll();
        $invalid = $h->a->request('POST', '/p/' . $h->token, [], ['q' . $q[0]['id'] => '9']);
        LiteAssert::same(422, $invalid['status'], 'out-of-range score rejected');

        $done = $h->a->request('POST', '/p/' . $h->token, [], ['q' . $q[0]['id'] => '2', 'q' . $q[1]['id'] => 'Delivery was late']);
        LiteAssert::same(200, $done['status']);
        $response = $h->a->pdo->query("SELECT * FROM cx_responses WHERE survey_id = {$h->surveyId}")->fetch();
        LiteAssert::same(2, (int) $response['score']);
        LiteAssert::same('dissatisfied', $response['sentiment']);
        LiteAssert::same('open', $response['follow_up_status']);
        LiteAssert::same('Delivery was late', $response['comment']);
        LiteAssert::same(2, $count('SELECT COUNT(*) FROM cx_response_answers WHERE response_id = ?', [(int) $response['id']]));
        LiteAssert::same('responded', $h->a->pdo->query("SELECT status FROM cx_invitations WHERE survey_id = {$h->surveyId}")->fetchColumn());

        LiteAssert::same(404, $h->a->request('GET', '/p/' . $h->token)['status'], 'used link');
        LiteAssert::same(404, $h->a->request('POST', '/p/' . $h->token, [], ['q' . $q[0]['id'] => '5'])['status'], 'no second answer');
        LiteAssert::same(1, $count('SELECT COUNT(*) FROM cx_responses WHERE survey_id = ?', [$h->surveyId]));
        LiteAssert::same(404, $h->a->request('GET', '/p/not-a-token')['status']);
        LiteAssert::same(404, $h->a->request('GET', '/p/' . str_repeat('A', 43))['status']);
    },

    'public links: expired, revoked and other-purpose links resolve to nothing' => function () use ($setup, $h) {
        $setup();
        $expired = $h->links->issue('cx_survey', 'cx_invitation', 999999, new \DateTimeImmutable('-1 minute'));
        LiteAssert::same(null, $h->links->resolve($expired['token']));
        $revoked = $h->links->issue('cx_survey', 'cx_invitation', 999998, new \DateTimeImmutable('+1 day'));
        LiteAssert::true($h->links->resolve($revoked['token']) !== null);
        $h->links->revoke($revoked['id']);
        LiteAssert::same(null, $h->links->resolve($revoked['token']));
        $single = $h->links->issue('cx_survey', 'cx_invitation', 999997, new \DateTimeImmutable('+1 day'), 'fa', 1);
        $resolved = $h->links->resolve($single['token']);
        LiteAssert::same('fa', $resolved->locale);
        LiteAssert::true($h->links->consume($resolved));
        LiteAssert::false($h->links->consume($resolved), 'a single-use link counts once (double submit loses)');
        LiteAssert::same(null, $h->links->resolve($single['token']));
        $unknownPurpose = $h->links->issue('nothing_registered', 'x', 1, null);
        LiteAssert::same(404, $h->a->request('GET', '/p/' . $unknownPurpose['token'])['status']);
        LiteAssert::true($h->links->newToken() !== $h->links->newToken());
    },

    'CX workspace: follow-up listed and closable; module gate hides /cx without entitlement' => function () use ($setup, $h) {
        $setup();
        $h->a->locale('en');
        $page = $h->a->request('GET', '/cx');
        LiteAssert::same(200, $page['status']);
        LiteAssert::contains('After purchase', $page['body']);
        LiteAssert::contains('Delivery was late', $page['body']);
        LiteAssert::same(200, $h->a->request('GET', '/cx/surveys/create')['status']);
        LiteAssert::same(200, $h->a->request('GET', '/cx/surveys/' . $h->surveyId . '/edit')['status']);

        $responseId = (int) $h->a->pdo->query("SELECT id FROM cx_responses WHERE survey_id = {$h->surveyId}")->fetchColumn();
        $h->a->request('POST', '/cx/responses/' . $responseId . '/follow-up');
        LiteAssert::same('done', $h->a->pdo->query("SELECT follow_up_status FROM cx_responses WHERE id = {$responseId}")->fetchColumn());

        $h->a->setOrgEntitled('customer_satisfaction', false);
        try {
            LiteAssert::same('notfound', $h->a->outcome('GET', '/cx'));
        } finally {
            $h->a->setOrgEntitled('customer_satisfaction', true);
        }
    },

    'disabled connection: webhooks ignored, queued jobs cancelled, no API call' => function () use ($setup, $h, $order, $signed) {
        $setup();
        $h->integrations->setEnabled($h->a->admin, $h->connId, false);
        $conn = $h->connections->findForJob($h->connId, $h->a->orgId);
        $body = (string) json_encode($order(960, 'processing', gmdate('Y-m-d\TH:i:s')));
        LiteAssert::same('ignored', $h->receiver->receive($conn->webhookKey(), $signed($body, (string) $conn->webhookSecret(), 'order.created'), $body)['result']);
        LiteAssert::throws(\DomainException::class, fn () => $h->integrations->requestSync($h->a->admin, $h->connId));
        $calls = count($h->http->requests);
        $h->a->pdo->exec("UPDATE integration_jobs SET available_at = '2000-01-01 00:00:00' WHERE connection_id = {$h->connId} AND status = 'pending'");
        $h->worker->run(5);
        LiteAssert::same($calls, count($h->http->requests));
        LiteAssert::same(0, (int) $h->a->pdo->query("SELECT COUNT(*) FROM integration_jobs WHERE connection_id = {$h->connId} AND status = 'pending'")->fetchColumn());
        $h->integrations->setEnabled($h->a->admin, $h->connId, true);
    },

    'delete connection removes its synced data (FK cascade)' => function () use ($setup, $h, $count) {
        $setup();
        $h->integrations->delete($h->a->admin, $h->connId);
        LiteAssert::same(0, $count('SELECT COUNT(*) FROM woo_orders WHERE connection_id = ?', [$h->connId]));
        LiteAssert::same(0, $count('SELECT COUNT(*) FROM integration_jobs WHERE connection_id = ?', [$h->connId]));
    },

    'translations: every new key exists in all five languages' => function () {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $keys = array_filter(array_keys($en), fn ($k) => preg_match('/^(integrations|cx|public|woocommerce)\./', $k) === 1);
        LiteAssert::true(count($keys) >= 180);
        foreach (['fa', 'ar', 'tr', 'fr'] as $lang) {
            $other = require $root . "/lang/{$lang}.php";
            foreach ($keys as $key) {
                LiteAssert::true(isset($other[$key]) && trim((string) $other[$key]) !== '', "{$lang}: {$key}");
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
