<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/**
 * The boundary between business modules and message DELIVERY.
 *
 * A business module (e.g. Customer Satisfaction) decides WHY, to WHOM,
 * WHAT and WHEN; it then asks "send this one message through an
 * available channel" here. The implementation — owned by Marketing,
 * which already has the SMS / Email / Bale channels, provider settings,
 * opt-outs and send history — decides HOW. Business modules never call
 * an SMS/Email/Bale provider directly and never duplicate channels.
 *
 * Bound in the container by the delivering module (Phase 2: Marketing).
 * When nothing is bound, callers keep their message waiting
 * ("waiting for a channel") instead of failing.
 */
interface TransactionalDeliveryInterface
{
    /**
     * Channel keys this organization can deliver on right now (e.g.
     * ["sms", "email", "bale"]), in the deliverer's preference order.
     *
     * @return string[]
     */
    public function availableChannels(int $organizationId): array;

    /** Sends (or queues) one message. Idempotent per $message->reference. */
    public function deliver(TransactionalMessage $message): DeliveryReceipt;
}
