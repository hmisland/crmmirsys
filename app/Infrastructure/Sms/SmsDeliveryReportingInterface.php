<?php

declare(strict_types=1);

namespace App\Infrastructure\Sms;

/**
 * Optional provider features used by bulk/marketing sends. A provider
 * that doesn't implement this still works for every existing caller;
 * Marketing then sends without provider-side deduplication and leaves
 * delivery as "unknown" (accepted only).
 */
interface SmsDeliveryReportingInterface
{
    /**
     * One message with our own unique id. A provider that supports it
     * (Kavenegar "localid") never sends twice for the same id — it
     * returns the earlier record instead.
     */
    public function sendWithLocalId(string $receptor, string $message, int $localId, ?string $sender = null): SmsResult;

    /**
     * Delivery state of earlier sends.
     *
     * @param string[] $messageIds provider message ids
     * @return array<string, array{state: string, code: int, text: string}>
     *   state: queued|sent|delivered|undelivered|failed|blocked|cancelled|unknown
     */
    public function deliveryStatuses(array $messageIds): array;

    /**
     * An earlier send found by our own id (Kavenegar
     * sms/statuslocalmessageid.json): whether the provider has it.
     *
     * @return array{found: bool, message_id: ?string, state: string}|null null = the provider could not be asked
     */
    public function findByLocalId(int $localId): ?array;
}
