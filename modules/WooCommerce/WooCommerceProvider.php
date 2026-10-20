<?php

declare(strict_types=1);

namespace Modules\WooCommerce;

use App\Infrastructure\Integrations\IntegrationConnection;
use App\Infrastructure\Integrations\IntegrationField;
use App\Infrastructure\Integrations\IntegrationJob;
use App\Infrastructure\Integrations\IntegrationProviderInterface;
use App\Infrastructure\Integrations\IntegrationResult;
use App\Infrastructure\Integrations\JobOutcome;
use App\Infrastructure\Integrations\ProvidesConnectionLinks;
use App\Infrastructure\Integrations\WebhookReceipt;
use Modules\WooCommerce\Api\StoreUrl;
use Modules\WooCommerce\Api\WooApiException;
use Modules\WooCommerce\Api\WooCommerceClient;
use Modules\WooCommerce\Services\WooOrderImporter;

/**
 * WooCommerce as an integration provider (one connection = one store).
 *
 * Sync (cron / manual) — incremental, orders only: customers and
 * products are taken from the orders themselves, so no extra API calls.
 * Keyset cursor on the order's modification time:
 *   GET /orders?orderby=modified&order=asc&modified_after={cursor}&dates_are_gmt=true&per_page=N
 * After each page the cursor moves to (newest modified − 1 s); the
 * one-second overlap re-reads orders that share the boundary second,
 * which the idempotent import absorbs. Only when a whole page shares
 * that one second does it fall back to the next page number. A job
 * reads at most pages_per_job pages, then hands over to a continuation
 * job (JobOutcome::done(more: true)). A new store starts initial_days
 * back — the shop's full history is never imported.
 *
 * Webhooks — order.created/updated/restored and customer.created/updated:
 * authenticated with WooCommerce's X-WC-Webhook-Signature
 * (base64 HMAC-SHA256 of the raw body with the connection's webhook
 * secret), then only queued. The verified payload is processed by the
 * worker without another API call.
 */
final class WooCommerceProvider implements IntegrationProviderInterface, ProvidesConnectionLinks
{
    public const KEY = 'woocommerce';

    private const ORDER_TOPICS = ['order.created', 'order.updated', 'order.restored'];
    private const CUSTOMER_TOPICS = ['customer.created', 'customer.updated'];

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly WooOrderImporter $importer,
        private readonly array $settings = [],
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function moduleKey(): string
    {
        return WooCommerceModule::KEY;
    }

    public function nameKey(): string
    {
        return 'integrations.woocommerce.name';
    }

    public function descriptionKey(): string
    {
        return 'integrations.woocommerce.description';
    }

    public function icon(): string
    {
        return 'package';
    }

    /** The shop's customer → contact sync settings (modules/WooCommerce/Controllers/ContactSyncController). */
    public function connectionLinks(array $connection): array
    {
        return [['href' => '/org/integrations/' . (int) $connection['id'] . '/contact-sync', 'icon' => 'users', 'label' => 'woocommerce.contacts.title']];
    }

    public function fields(): array
    {
        return [
            new IntegrationField('store_url', 'integrations.woocommerce.store_url', IntegrationField::URL, true, 'integrations.woocommerce.store_url_hint'),
            new IntegrationField('consumer_key', 'integrations.woocommerce.consumer_key', IntegrationField::SECRET, true, 'integrations.woocommerce.keys_hint', 120),
            new IntegrationField('consumer_secret', 'integrations.woocommerce.consumer_secret', IntegrationField::SECRET, true, null, 120),
        ];
    }

    public function validate(array &$config, array $secrets, bool $isNew): array
    {
        $errors = [];
        $url = StoreUrl::normalize((string) ($config['store_url'] ?? ''));
        if ($url === null) {
            $errors['store_url'] = 'integrations.woocommerce.error.store_url';
        } else {
            $config['store_url'] = $url;
        }
        if (isset($secrets['consumer_key']) && preg_match('/^ck_[A-Za-z0-9]{20,100}$/', $secrets['consumer_key']) !== 1) {
            $errors['consumer_key'] = 'integrations.woocommerce.error.consumer_key';
        }
        if (isset($secrets['consumer_secret']) && preg_match('/^cs_[A-Za-z0-9]{20,100}$/', $secrets['consumer_secret']) !== 1) {
            $errors['consumer_secret'] = 'integrations.woocommerce.error.consumer_secret';
        }

        return $errors;
    }

    public function testConnection(IntegrationConnection $connection): IntegrationResult
    {
        try {
            $this->client->getList($connection, 'orders', ['per_page' => 1]);

            return IntegrationResult::ok();
        } catch (WooApiException $e) {
            return IntegrationResult::failed($e->errorKey);
        }
    }

    public function syncIntervalMinutes(IntegrationConnection $connection): int
    {
        return max(5, (int) ($this->settings['sync_interval_minutes'] ?? 15));
    }

    public function syncResources(): array
    {
        return ['orders'];
    }

    public function receiveWebhook(IntegrationConnection $connection, array $headers, string $rawBody): WebhookReceipt
    {
        $signature = (string) ($headers['X-WC-WEBHOOK-SIGNATURE'] ?? '');
        if ($signature === '') {
            // WooCommerce's "ping" when a webhook is saved: unsigned, body "webhook_id=N". Nothing to do.
            return preg_match('/^webhook_id=\d+$/', trim($rawBody)) === 1 ? WebhookReceipt::ignored() : WebhookReceipt::rejected();
        }
        $secret = $connection->webhookSecret();
        if ($secret === null || !hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $signature)) {
            return WebhookReceipt::rejected();
        }

        $topic = strtolower(trim((string) ($headers['X-WC-WEBHOOK-TOPIC'] ?? '')));
        $resource = in_array($topic, self::ORDER_TOPICS, true) ? 'order' : (in_array($topic, self::CUSTOMER_TOPICS, true) ? 'customer' : null);
        if ($resource === null) {
            return WebhookReceipt::ignored();
        }
        $payload = json_decode($rawBody, true);
        $id = is_array($payload) ? (int) ($payload['id'] ?? 0) : 0;
        if ($id <= 0) {
            return WebhookReceipt::ignored();
        }

        return WebhookReceipt::queue($resource, (string) $id, $rawBody, sha1($topic . '|' . $rawBody));
    }

    public function handleJob(IntegrationConnection $connection, IntegrationJob $job): JobOutcome
    {
        try {
            if ($job->type === IntegrationJob::WEBHOOK) {
                return $this->handleWebhookJob($connection, $job);
            }
            if ($job->resource === 'orders') {
                return $this->syncOrders($connection);
            }

            return JobOutcome::failed('integrations.error.unknown_job');
        } catch (WooApiException $e) {
            return $e->retryable ? JobOutcome::retry($e->errorKey) : JobOutcome::failed($e->errorKey);
        }
    }

    private function handleWebhookJob(IntegrationConnection $connection, IntegrationJob $job): JobOutcome
    {
        $payload = json_decode((string) $job->payload, true);
        if ($job->resource === 'order') {
            if (!is_array($payload) || !isset($payload['status'])) {
                // Payload unusable: fetch the order itself (one call).
                $payload = $this->client->getOne($connection, 'orders/' . (int) $job->externalId);
            }

            return $this->importer->importOrder($connection->id(), $payload) ? JobOutcome::done(1) : JobOutcome::failed('woocommerce.error.bad_payload');
        }
        if ($job->resource === 'customer' && is_array($payload)) {
            return $this->importer->importCustomer($connection->id(), $payload) ? JobOutcome::done(1) : JobOutcome::failed('woocommerce.error.bad_payload');
        }

        return JobOutcome::failed('woocommerce.error.bad_payload');
    }

    private function syncOrders(IntegrationConnection $connection): JobOutcome
    {
        $perPage = max(1, min(100, (int) ($this->settings['per_page'] ?? 50))); // config/integrations.php keeps it 10–100
        $maxPages = max(1, (int) ($this->settings['pages_per_job'] ?? 2));
        $state = $connection->syncState()['orders'] ?? [];
        $after = is_string($state['after'] ?? null) && $state['after'] !== ''
            ? $state['after']
            : gmdate('Y-m-d\TH:i:s', time() - 86400 * max(1, (int) ($this->settings['initial_days'] ?? 30)));
        $page = max(1, (int) ($state['page'] ?? 1));
        $processed = 0;

        for ($i = 0; $i < $maxPages; $i++) {
            $result = $this->client->getList($connection, 'orders', [
                'orderby' => 'modified',
                'order' => 'asc',
                'modified_after' => $after,
                'dates_are_gmt' => 'true',
                'per_page' => $perPage,
                'page' => $page,
            ]);
            $newest = null;
            foreach ($result['items'] as $order) {
                if (is_array($order) && $this->importer->importOrder($connection->id(), $order)) {
                    $processed++;
                }
                $modified = is_array($order) ? WooOrderImporter::gmt($order['date_modified_gmt'] ?? null) : null;
                if ($modified !== null && ($newest === null || $modified > $newest)) {
                    $newest = $modified;
                }
            }

            // Caught up only when the shop says so: no orders, or a short LAST page by its own page count.
            // A short page alone is not enough — many stores cap per_page (e.g. at 10 or 20) below what we ask.
            if ($result['items'] === [] || (count($result['items']) < $perPage && $page >= $result['total_pages'])) {
                // Caught up: next run starts one second before the newest change seen.
                if ($newest !== null) {
                    $after = max($after, gmdate('Y-m-d\TH:i:s', strtotime($newest . ' UTC') - 1));
                }

                return JobOutcome::done($processed, ['orders' => ['after' => $after, 'page' => 1]], false);
            }

            // More orders exist: move the keyset cursor (or step to the next page when it cannot move).
            $candidate = $newest !== null ? gmdate('Y-m-d\TH:i:s', strtotime($newest . ' UTC') - 1) : $after;
            if ($candidate > $after) {
                [$after, $page] = [$candidate, 1];
            } else {
                $page++; // a whole page within one second: step through pages instead
            }
        }

        return JobOutcome::done($processed, ['orders' => ['after' => $after, 'page' => $page]], true);
    }
}
