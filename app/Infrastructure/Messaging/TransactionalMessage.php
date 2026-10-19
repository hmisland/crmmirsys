<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/**
 * One message a business module asks to be delivered (see
 * TransactionalDeliveryInterface). $reference is the caller's stable
 * idempotency key, e.g. "cx_invitation:42:1" — delivering the same
 * reference twice must not send twice. $contactId is optional (the CRM
 * contact, when known) so the deliverer can apply that contact's
 * opt-outs and record history on it.
 */
final class TransactionalMessage
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $channel,
        public readonly string $address,
        public readonly string $recipientName,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $purpose,
        public readonly string $reference,
        public readonly ?int $contactId = null,
        public readonly ?string $locale = null,
    ) {
    }
}
