<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use App\Application\Services\SmsService;

/**
 * SMS through the existing SmsService — so the organization's chosen
 * account (platform account billed from credit, or its own Kavenegar
 * key), number normalization, billing/refunds and sms_logs all work
 * exactly as for every other SMS in the app.
 *
 * The per-message id goes to Kavenegar as "localid": Kavenegar never
 * sends twice for the same id, so a retried message cannot double-send.
 * A failure without a provider status code is a network failure
 * (nothing was accepted) and is retried; provider rejections (bad line,
 * no credit, …) are final until someone retries them.
 */
final class SmsChannel implements MarketingChannelInterface
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    public function key(): string
    {
        return 'sms';
    }

    public function moduleKey(): string
    {
        return 'marketing';
    }

    public function unavailableReason(array $campaign): ?string
    {
        return $this->sms->canSend() ? null : 'marketing.error.sms_not_configured';
    }

    public function send(array $campaign, string $address, string $recipientName, array $content, int $dedupeId, ?int $billedUnits = null, ?\Closure $onBilling = null): DispatchResult
    {
        $provider = $this->sms->currentSenderName();
        // sms_context: set by TransactionalDelivery (no campaign); campaigns keep their usual log label.
        $context = (string) ($campaign['sms_context'] ?? 'marketing.campaign:' . (int) $campaign['id']);
        $result = $this->sms->sendOne($address, $content['text'], $context, $dedupeId, $billedUnits, $onBilling);

        if ($result->success) {
            return new DispatchResult(true, $provider, $result->messageIds[0] ?? null);
        }

        $networkFailure = $result->statusCode === null && str_starts_with((string) $result->errorMessage, 'Could not reach');

        return new DispatchResult(false, $provider, null, (string) $result->errorMessage, $networkFailure, $networkFailure ? 120 : null);
    }
}
