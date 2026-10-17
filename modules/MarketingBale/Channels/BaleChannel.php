<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Channels;

use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\Channels\DispatchResult;
use Modules\Marketing\Channels\MarketingChannelInterface;
use Modules\Marketing\Channels\PhoneTextChannel;
use Modules\Marketing\Channels\RecoverableChannel;
use Modules\MarketingBale\Billing\BillableMessageSink;
use Modules\MarketingBale\Safir\SafirClient;
use Modules\MarketingBale\Safir\SafirOutcome;
use Modules\MarketingBale\Safir\SafirPhone;
use Modules\MarketingBale\Services\BaleSettingsService;

/**
 * Bale through Safir, as a Marketing channel ("bale"): campaigns,
 * audiences, approval, queue, worker, opt-outs (channel "bale", separate
 * from SMS) and reports are Marketing's; this class only sends one text
 * to one number with the organization's own Safir account.
 *
 * Outcome → queue (see SafirOutcome):
 *   accepted    → sent (= accepted by Safir, never "delivered")
 *   failed      → failed, final
 *   unsupported → skipped / unsupported_recipient
 *   retry       → back to the queue with a delay, same request_id; rate
 *                 limits stop the batch and pause the organization 60 s
 *   pause       → back to the queue without using an attempt, batch stops,
 *                 organization paused (no credit / contact limit) or
 *                 blocked until new credentials (key refused)
 *   unknown     → failed + ambiguous: never retried automatically
 *
 * Internal throttle (config messaging.bale): at most requests_per_second
 * requests from this process and per_run messages per campaign per run.
 * These are our own safety settings — Safir documents no numeric limit.
 */
final class BaleChannel implements MarketingChannelInterface, PhoneTextChannel, RecoverableChannel
{
    public const KEY = 'bale';
    public const MODULE_KEY = 'bale_messaging';
    public const PROVIDER = 'safir';
    public const MAX_TEXT_LENGTH = 4000;

    private float $lastRequestAt = 0.0;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    public function __construct(
        private readonly SafirClient $client,
        private readonly BaleSettingsService $settings,
        private readonly BillableMessageSink $billing,
        private readonly ?LoggerInterface $logger = null,
        private readonly float $requestsPerSecond = 5.0,
        private readonly int $perRun = 200,
        private readonly int $pauseSeconds = 600,
        private readonly int $rateLimitSeconds = 60,
        private readonly int $approvalsPerHour = 20,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public function unavailableReason(array $campaign): ?string
    {
        return $this->settings->unavailableReason();
    }

    public function send(array $campaign, string $address, string $recipientName, array $content, int $dedupeId, ?int $billedUnits = null, ?\Closure $onBilling = null): DispatchResult
    {
        $text = trim((string) ($content['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return new DispatchResult(false, self::PROVIDER, null, 'Message text is empty or longer than ' . self::MAX_TEXT_LENGTH . ' characters.');
        }
        $credentials = $this->settings->credentials();
        if ($credentials === null) {
            return new DispatchResult(false, self::PROVIDER, null, 'Bale is not configured.', true, $this->pauseSeconds, false, true, $this->settings->unavailableReason() ?? 'bale.error.not_configured');
        }

        $requestId = self::requestId($dedupeId);
        $this->throttle();
        $outcome = $this->client->sendText($credentials['api_key'], $credentials['bot_id'], $address, $text, $requestId);

        return $this->toDispatchResult($outcome, $campaign, $requestId);
    }

    // --- PhoneTextChannel ------------------------------------------------------

    public function classifyAddress(string $raw): array
    {
        return SafirPhone::classify($raw);
    }

    public function maxTextLength(): int
    {
        return self::MAX_TEXT_LENGTH;
    }

    public function requiresConsentConfirmation(): bool
    {
        return true;
    }

    public function approvalsPerUserPerHour(): int
    {
        return max(0, $this->approvalsPerHour);
    }

    // --- RecoverableChannel ----------------------------------------------------

    /** Safir has no status query: a row interrupted mid-send may have gone out. */
    public function recoverStale(array $campaign, array $row, int $dedupeId): array
    {
        return ['status' => 'failed', 'ambiguous' => 1, 'provider' => self::PROVIDER, 'error' => 'Safir: interrupted_while_sending — not retried automatically to avoid a duplicate.'];
    }

    public function exceptionIsAmbiguous(): bool
    {
        return true;
    }

    public function maxPerRun(): int
    {
        return max(1, $this->perRun);
    }

    public function allowsConfirmedResend(): bool
    {
        return true;
    }

    /** Safir request_id for a message: "crm-" + installation prefix + row id (stable across safe retries). */
    public static function requestId(int $dedupeId): string
    {
        return 'crm-' . $dedupeId;
    }

    // --- Internals -------------------------------------------------------------

    private function toDispatchResult(SafirOutcome $outcome, array $campaign, string $requestId): DispatchResult
    {
        $error = $outcome->label();

        switch ($outcome->kind) {
            case SafirOutcome::ACCEPTED:
                $this->safely(fn () => $this->settings->recordAccepted());
                $this->event($campaign, $requestId, $outcome);

                return new DispatchResult(true, self::PROVIDER, $outcome->messageId);

            case SafirOutcome::UNSUPPORTED:
                $this->event($campaign, $requestId, $outcome);

                return new DispatchResult(false, self::PROVIDER, null, $error, false, null, false, false, null, SafirPhone::UNSUPPORTED);

            case SafirOutcome::RETRY:
                $rateLimited = $outcome->reason !== 'connection_failed';
                if ($rateLimited) {
                    $this->safely(fn () => $this->settings->pause($this->rateLimitSeconds, $outcome->reason));
                }

                return new DispatchResult(false, self::PROVIDER, null, $error, true, $rateLimited ? $this->rateLimitSeconds : 120, false, $rateLimited);

            case SafirOutcome::PAUSE:
                if ($outcome->reason === 'credentials_rejected') {
                    $this->safely(fn () => $this->settings->recordRejected());
                    $reason = 'bale.error.credentials_rejected';
                } else {
                    $this->safely(fn () => $this->settings->pause($this->pauseSeconds, $outcome->reason));
                    $reason = 'bale.pause.' . $outcome->reason;
                }

                return new DispatchResult(false, self::PROVIDER, null, $error, true, $this->pauseSeconds, false, true, $reason);

            case SafirOutcome::FAILED:
                $this->event($campaign, $requestId, $outcome);

                return new DispatchResult(false, self::PROVIDER, null, $error);

            default: // unknown
                $this->event($campaign, $requestId, $outcome);

                return new DispatchResult(false, self::PROVIDER, null, $error, false, null, true);
        }
    }

    private function event(array $campaign, string $requestId, SafirOutcome $outcome): void
    {
        $kind = $outcome->kind === SafirOutcome::ACCEPTED || $outcome->kind === SafirOutcome::UNSUPPORTED || $outcome->kind === SafirOutcome::FAILED ? $outcome->kind : SafirOutcome::UNKNOWN;
        $this->safely(fn () => $this->billing->record([
            'event_key' => 'bale:' . $requestId . ':' . $kind,
            'organization_id' => OrganizationContext::id(),
            'campaign_id' => (int) ($campaign['id'] ?? 0),
            'request_id' => $requestId,
            'provider_message_id' => $outcome->messageId,
            'outcome' => $kind,
            'reason' => $outcome->reason,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ]));
    }

    /** Side work (settings bookkeeping, billing events) must never change a message's outcome. */
    private function safely(\Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger?->error('Bale channel: bookkeeping failed (' . get_class($e) . ')');
        }
    }

    private function throttle(): void
    {
        if ($this->requestsPerSecond <= 0) {
            return;
        }
        $interval = 1.0 / $this->requestsPerSecond;
        $wait = $this->lastRequestAt + $interval - microtime(true);
        if ($this->lastRequestAt > 0 && $wait > 0) {
            ($this->sleep)($wait);
        }
        $this->lastRequestAt = microtime(true);
    }
}
