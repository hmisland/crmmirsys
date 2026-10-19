<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

/**
 * One kind of external system (WooCommerce today; later e.g. another
 * shop or a booking system). A module implements this and registers it
 * in IntegrationRegistry from its register() — Organization settings →
 * Integrations, the webhook endpoint and the cron worker then work for
 * it without any provider-specific code in Core.
 *
 * Core owns: connection storage + encryption, enable/disable, status,
 * last sync bookkeeping, the job queue, scheduling, retries, the
 * webhook URL and the settings page. The provider owns: what its
 * settings mean, talking to the external API, verifying its webhooks,
 * and turning external data into CRM data (its own tables).
 */
interface IntegrationProviderInterface
{
    /** Stable key stored in integration_connections.provider, e.g. "woocommerce". */
    public function key(): string;

    /** Entitlement module key the organization needs (ModuleGate / module purchase). */
    public function moduleKey(): string;

    public function nameKey(): string;

    public function descriptionKey(): string;

    public function icon(): string;

    /** @return IntegrationField[] settings the connection form shows */
    public function fields(): array;

    /**
     * Validates and normalizes submitted settings. $secrets holds only
     * the secret fields that were filled in (an empty secret field
     * keeps the stored value); $isNew: no secrets are stored yet.
     *
     * @return array<string, string> field name => translation key of the error; [] = valid
     */
    public function validate(array &$config, array $secrets, bool $isNew): array;

    /** Calls the external API once with the stored credentials. */
    public function testConnection(IntegrationConnection $connection): IntegrationResult;

    /** Minutes between scheduled incremental syncs; 0 = no scheduled sync. */
    public function syncIntervalMinutes(IntegrationConnection $connection): int;

    /** Resources a sync covers, in order (each becomes one queued job), e.g. ["customers", "orders"]. */
    public function syncResources(): array;

    /**
     * Authenticates a webhook request (signature over the raw body) and
     * says what to queue. Must be cheap: no API calls, no database
     * writes — the request only queues work.
     *
     * @param array<string, string> $headers upper-cased names, e.g. "X-WC-WEBHOOK-SIGNATURE"
     */
    public function receiveWebhook(IntegrationConnection $connection, array $headers, string $rawBody): WebhookReceipt;

    /** Processes one queued job (a sync batch or a received webhook). Called from cron only. */
    public function handleJob(IntegrationConnection $connection, IntegrationJob $job): JobOutcome;
}
