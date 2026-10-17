<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

/**
 * One messaging channel (sms, email, …). Campaign orchestration, the
 * queue and the UI only talk to this interface — a future channel is one
 * more implementation registered in ChannelRegistry, with its own
 * provider adapters behind it (SMS: SmsService/SmsSenderInterface,
 * email: EmailProviderService/EmailProviderInterface).
 */
interface MarketingChannelInterface
{
    public function key(): string;

    /** Entitlement module key the organization needs for this channel. */
    public function moduleKey(): string;

    /**
     * Whether the current organization can send on this channel with
     * the campaign's provider; null = ready, otherwise a translation key.
     */
    public function unavailableReason(array $campaign): ?string;

    /**
     * Sends one rendered message to one address.
     *
     * @param array{subject: string, text: string, html: string, unsubscribe_url: string} $content
     * @param int $dedupeId unique id of this message, passed to providers that deduplicate
     * @param int|null $billedUnits units this message already paid for (never charged twice)
     * @param \Closure(?int): void|null $onBilling called after a charge (units) or a refund (null)
     */
    public function send(array $campaign, string $address, string $recipientName, array $content, int $dedupeId, ?int $billedUnits = null, ?\Closure $onBilling = null): DispatchResult;
}
