<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/** marketing_webhook_events — the idempotency ledger for provider events. */
final class WebhookEventRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_webhook_events';
    }

    /** True when the event is new (recorded now); false when it was already processed. */
    public function recordOnce(string $provider, string $eventKey, string $type, string $messageId): bool
    {
        $stmt = $this->query(
            'INSERT IGNORE INTO marketing_webhook_events (organization_id, provider, event_key, event_type, provider_message_id)
             VALUES (:org_id, :provider, :event_key, :event_type, :message_id)',
            ['org_id' => OrganizationContext::id(), 'provider' => $provider, 'event_key' => $eventKey, 'event_type' => $type, 'message_id' => mb_substr($messageId, 0, 255)]
        );

        return $stmt->rowCount() === 1;
    }
}
