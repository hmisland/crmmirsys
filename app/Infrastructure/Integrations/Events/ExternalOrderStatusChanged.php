<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Events;

use App\Core\Events\EventInterface;

/**
 * An order from an external source (WooCommerce today) was first seen
 * or changed status. Source-neutral on purpose: listeners (e.g.
 * Customer Satisfaction) react to "an order was completed", not to
 * "WooCommerce did X", so a future shop integration raises the same
 * event and no listener changes.
 *
 * $orderRef is an opaque, stable internal reference
 * ("{source}:{connectionId}:{externalOrderId}") for idempotency — it is
 * never shown to customers. $occurredAt (UTC, Y-m-d H:i:s) is when the
 * status change happened in the source system, so listeners can ignore
 * old orders seen for the first time during an initial import.
 * $previousStatus is null when the order is new to the CRM.
 */
final class ExternalOrderStatusChanged implements EventInterface
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $source,
        public readonly int $connectionId,
        public readonly string $orderRef,
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly ?string $previousStatus,
        public readonly string $occurredAt,
        public readonly string $customerName,
        public readonly ?string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ?int $contactId,
        public readonly string $total,
        public readonly ?string $currency,
    ) {
    }

    /** Normalized meaning across sources: completed / cancelled / refunded / other. */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
