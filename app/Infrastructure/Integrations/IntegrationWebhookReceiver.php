<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Infrastructure\Modules\OrganizationEntitlements;

/**
 * POST /webhooks/integrations/{key} — deliberately lightweight: find
 * the connection by its random key, let the provider verify the
 * signature over the raw body, queue ONE job, answer. No external API
 * call and no business processing happens in the request; the cron
 * worker does that.
 *
 * Answers: 404 unknown key; 413 body too large; 401 bad signature;
 * 200 otherwise (also for a disabled connection or an organization
 * without the module — the delivery is dropped, but the store is not
 * pushed into disabling its webhook after repeated failures).
 */
final class IntegrationWebhookReceiver
{
    public const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationConnectionRepository $connections,
        private readonly IntegrationJobRepository $jobs,
        private readonly OrganizationEntitlements $entitlements,
    ) {
    }

    /**
     * @param array<string, string> $headers upper-cased names
     * @return array{status: int, result: string}
     */
    public function receive(string $key, array $headers, string $rawBody): array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $key) !== 1) {
            return ['status' => 404, 'result' => 'not_found'];
        }
        $connection = $this->connections->findByWebhookKey($key);
        $provider = $connection !== null ? $this->registry->get($connection->provider()) : null;
        if ($connection === null || $provider === null) {
            return ['status' => 404, 'result' => 'not_found'];
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return ['status' => 413, 'result' => 'too_large'];
        }

        $receipt = $provider->receiveWebhook($connection, $headers, $rawBody);
        if ($receipt->decision === WebhookReceipt::REJECTED) {
            return ['status' => 401, 'result' => 'rejected'];
        }

        $this->connections->touch($connection->id(), $connection->organizationId(), ['last_webhook_at' => date('Y-m-d H:i:s')]);

        if ($receipt->decision === WebhookReceipt::IGNORED
            || !$connection->enabled()
            || !$this->entitlements->organizationHas($connection->organizationId(), $provider->moduleKey())) {
            return ['status' => 200, 'result' => 'ignored'];
        }

        $queued = $this->jobs->enqueue(
            $connection->organizationId(),
            $connection->id(),
            IntegrationJob::WEBHOOK,
            (string) $receipt->resource,
            $receipt->externalId,
            $receipt->payload,
            'wh:' . $connection->id() . ':' . $receipt->dedupeKey,
        );

        return ['status' => 200, 'result' => $queued ? 'queued' : 'duplicate'];
    }
}
