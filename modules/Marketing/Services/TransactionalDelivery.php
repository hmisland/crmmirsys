<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Application\Services\TimelineService;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Messaging\DeliveryReceipt;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Messaging\TransactionalMessage;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\DispatchResult;
use Modules\Marketing\Channels\MarketingChannelInterface;
use Modules\Marketing\Channels\RecoverableChannel;
use Modules\Marketing\MarketingModule;
use Modules\Marketing\SuppressionRepository;
use Modules\Marketing\TransactionalMessageRepository;

/**
 * Phase 2: Marketing's implementation of Core's
 * TransactionalDeliveryInterface — ONE message, asked for by another
 * module (e.g. a Customer Satisfaction survey invitation), sent through
 * Marketing's existing channels (SMS → SmsService, Email → Sender/Brevo,
 * Bale → Safir when MarketingBale is installed). No campaign, no queue:
 * the caller (a cron task) already decides when to send and retries on
 * a "retry" receipt.
 *
 * Rules:
 *  - available only while the organization holds "marketing" and the
 *    channel's own module, and the channel's provider is ready;
 *  - every opt-out on the channel's list blocks the message (any reason,
 *    including "unsubscribed": a survey request is not a receipt);
 *  - idempotent per (organization, reference): a sent reference is never
 *    sent again; a row is claimed before the provider call, so two runs
 *    never send the same reference; an outcome that may have reached the
 *    provider (timeout, interrupted run) is final ("unknown"), never
 *    resent automatically — except SMS, where the provider dedupe id
 *    (Kavenegar localid) makes a resend safe;
 *  - history in marketing_transactional_messages (no message text: it
 *    may carry a single-use link) and on the contact's timeline.
 */
final class TransactionalDelivery implements TransactionalDeliveryInterface
{
    private const LOCK_MINUTES = 5;
    /** Keeps provider dedupe ids apart from campaign rows (MessageComposer::dedupeId). */
    private const DEDUPE_OFFSET = 900_000_000_000;
    private const MAX_REFERENCE_LENGTH = 120;

    public function __construct(
        private readonly TransactionalMessageRepository $messages,
        private readonly SuppressionRepository $suppressions,
        private readonly ChannelRegistry $channels,
        private readonly Entitlements $entitlements,
        private readonly MessageComposer $composer,
        private readonly EmailProviderService $email,
        private readonly SmsService $sms,
        private readonly TimelineService $timeline,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function availableChannels(int $organizationId): array
    {
        return OrganizationContext::runAs($organizationId, function () use ($organizationId): array {
            // A module switched on or off since the last call counts now (long cron runs).
            $this->entitlements->reset();

            return array_values(array_filter(
                $this->channels->keys(),
                fn (string $key): bool => $this->blockedReason($organizationId, $key) === null,
            ));
        });
    }

    public function deliver(TransactionalMessage $message): DeliveryReceipt
    {
        $reference = trim($message->reference);
        if ($reference === '' || mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            return DeliveryReceipt::failed('marketing.transactional.error.invalid_request');
        }

        return OrganizationContext::runAs($message->organizationId, fn (): DeliveryReceipt => $this->deliverInOrganization($message, $reference));
    }

    private function deliverInOrganization(TransactionalMessage $message, string $reference): DeliveryReceipt
    {
        $row = $this->messages->findByReference($reference);
        if ($row !== null) {
            $earlier = $this->earlierOutcome($row);
            if ($earlier !== null) {
                return $earlier;
            }
        }

        $key = $message->channel;
        $channel = $this->channels->get($key);
        if ($channel === null) {
            return DeliveryReceipt::failed('marketing.error.invalid_channel');
        }
        [$address, $addressError] = $this->address($key, $message->address);
        if ($address === null) {
            return DeliveryReceipt::failed($addressError);
        }
        $text = trim($message->text);
        if ($text === '') {
            return DeliveryReceipt::failed('marketing.error.body_required');
        }
        $subject = trim($message->subject);
        if ($key === 'email' && $subject === '') {
            return DeliveryReceipt::failed('marketing.error.subject_required');
        }

        $blocked = $this->blockedReason($message->organizationId, $key);
        if ($blocked !== null) {
            // Nothing recorded: the caller keeps its message and asks again later.
            return DeliveryReceipt::retry($blocked);
        }

        $contactId = $message->contactId !== null && $this->messages->contactExists($message->contactId) ? $message->contactId : null;
        $fields = [
            'purpose' => mb_substr($message->purpose !== '' ? $message->purpose : 'transactional', 0, 40),
            'channel' => $key,
            'address' => $address,
            'contact_id' => $contactId,
            'recipient_name' => $message->recipientName !== '' ? mb_substr($message->recipientName, 0, 200) : null,
        ];

        $suppressed = $this->suppressions->reasonsFor($key, [$address])[$address] ?? null;
        if ($suppressed !== null) {
            $skip = ['status' => 'skipped', 'skip_reason' => 'suppressed_' . $suppressed, 'error_key' => 'marketing.transactional.error.suppressed'];
            if ($row === null) {
                $this->messages->create(array_merge($fields, ['reference' => $reference], $skip));
            } else {
                $this->messages->updateUnclaimed((int) $row['id'], array_merge($fields, $skip));
            }

            return DeliveryReceipt::failed('marketing.transactional.error.suppressed');
        }

        // Claim: a new row, or a "retry" row (certainly not sent) for another attempt.
        $token = bin2hex(random_bytes(12));
        $lockUntil = $this->now('+' . self::LOCK_MINUTES . ' minutes');
        if ($row === null) {
            $id = $this->messages->create(array_merge($fields, [
                'reference' => $reference, 'status' => 'processing', 'attempts' => 1, 'lock_token' => $token, 'locked_until' => $lockUntil,
            ]));
            if ($id === null) {
                // Another run created it a moment ago.
                $current = $this->messages->findByReference($reference);

                return ($current !== null ? $this->earlierOutcome($current) : null) ?? DeliveryReceipt::retry('marketing.transactional.error.in_progress');
            }
            $billed = null;
        } else {
            $id = (int) $row['id'];
            // Units already paid stay with the message only on the same channel (SMS refunds a failed send anyway).
            $billed = $row['channel'] === $key && $row['billed_units'] !== null ? (int) $row['billed_units'] : null;
            if (!$this->messages->claimRetry($id, $token, $lockUntil, $fields + ['billed_units' => $billed])) {
                return DeliveryReceipt::retry('marketing.transactional.error.in_progress');
            }
        }

        return $this->send($channel, $id, $token, $fields, $message, $subject, $text, $billed);
    }

    private function send(MarketingChannelInterface $channel, int $id, string $token, array $fields, TransactionalMessage $message, string $subject, string $text, ?int $billed): DeliveryReceipt
    {
        $key = $channel->key();
        try {
            $result = $channel->send(
                $this->pseudoCampaign($key, $id),
                $fields['address'],
                (string) ($fields['recipient_name'] ?? ''),
                ['subject' => $subject, 'text' => $text, 'html' => $key === 'email' ? $this->html($text, $message->locale) : '', 'unsubscribe_url' => ''],
                $this->dedupeId($id),
                $billed,
                // Stored the moment the charge happens, so a crash right after it can't lead to a second charge.
                fn (?int $units) => $this->messages->setBilledUnits($id, $token, $units),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Marketing transactional: message ' . $id . ' threw: ' . get_class($e));
            $ambiguous = $channel instanceof RecoverableChannel ? $channel->exceptionIsAmbiguous() : $key !== 'sms';
            if ($ambiguous) {
                $this->messages->finish($id, $token, ['status' => 'failed', 'ambiguous' => 1, 'error' => 'Internal error while sending.', 'error_key' => 'marketing.transactional.error.unknown']);

                return DeliveryReceipt::failed('marketing.transactional.error.unknown');
            }
            $this->messages->finish($id, $token, ['status' => 'retry', 'error' => 'Internal error while sending.', 'error_key' => 'marketing.transactional.error.provider_retry']);

            return DeliveryReceipt::retry('marketing.transactional.error.provider_retry');
        }

        return $this->record($id, $token, $result, $fields, $key);
    }

    /** Stores a provider answer and translates it into the caller's receipt. */
    private function record(int $id, string $token, DispatchResult $result, array $fields, string $key): DeliveryReceipt
    {
        $provider = mb_substr($result->provider, 0, 40);
        $error = $result->error !== null ? mb_substr($result->error, 0, 500) : null;

        if ($result->success) {
            $reference = $result->providerMessageId !== null ? mb_substr($result->providerMessageId, 0, 255) : null;
            $this->messages->finish($id, $token, [
                'status' => 'sent', 'provider' => $provider, 'provider_message_id' => $reference,
                'sent_at' => $this->now(), 'error' => null, 'error_key' => null,
            ]);
            if ($fields['contact_id'] !== null) {
                $this->timeline->record('contact', (int) $fields['contact_id'], 'marketing.transactional_sent', sprintf('Transactional %s message sent (%s)', $key, $fields['purpose']), null);
            }

            return DeliveryReceipt::accepted($reference);
        }

        [$status, $errorKey, $extra] = match (true) {
            // The channel cannot serve this recipient (e.g. no Bale account): final.
            $result->skipReason !== null => ['skipped', 'marketing.transactional.error.unsupported_recipient', ['skip_reason' => mb_substr($result->skipReason, 0, 40)]],
            // Nothing was sent and the account cannot send for now (no credit, key refused, paused).
            $result->pauseReason !== null => ['retry', mb_substr($result->pauseReason, 0, 100), []],
            $result->retryable => ['retry', 'marketing.transactional.error.provider_retry', []],
            $result->ambiguous => ['failed', 'marketing.transactional.error.unknown', ['ambiguous' => 1]],
            default => ['failed', 'marketing.transactional.error.rejected', []],
        };
        $this->messages->finish($id, $token, array_merge(['status' => $status, 'provider' => $provider, 'error' => $error, 'error_key' => $errorKey], $extra));

        return $status === 'retry' ? DeliveryReceipt::retry($errorKey) : DeliveryReceipt::failed($errorKey);
    }

    /**
     * The answer for a reference seen before, or null when sending (again)
     * is allowed: sent → accepted; failed / skipped → the same failure;
     * claimed by a live run → retry later; claimed by a run that died →
     * recovered first (see recoverStale()).
     */
    private function earlierOutcome(array $row): ?DeliveryReceipt
    {
        switch ($row['status']) {
            case 'sent':
                return DeliveryReceipt::accepted($row['provider_message_id'] !== null ? (string) $row['provider_message_id'] : null);
            case 'failed':
            case 'skipped':
                return DeliveryReceipt::failed((string) ($row['error_key'] ?? 'marketing.transactional.error.rejected'));
            case 'processing':
                if ($row['locked_until'] !== null && (string) $row['locked_until'] > $this->now()) {
                    return DeliveryReceipt::retry('marketing.transactional.error.in_progress');
                }

                return $this->recoverStale($row);
            default: // retry
                return null;
        }
    }

    /**
     * A row left in "processing" by a run that died (it may or may not
     * have reached the provider). SMS: ask the provider for our dedupe
     * id — found → sent; otherwise a resend is safe (the localid still
     * blocks a duplicate, billed_units a second charge). Every other
     * channel: unknown, final.
     */
    private function recoverStale(array $row): ?DeliveryReceipt
    {
        $id = (int) $row['id'];
        if ($row['channel'] === 'sms') {
            $provider = $row['provider'] !== null ? (string) $row['provider'] : $this->sms->currentSenderName();
            $found = $this->sms->findByLocalId($this->dedupeId($id), $provider);
            if ($found !== null && $found['found']) {
                $this->messages->finish($id, null, [
                    'status' => 'sent', 'provider' => mb_substr($provider, 0, 40), 'provider_message_id' => $found['message_id'],
                    'sent_at' => $this->now(), 'error' => null, 'error_key' => null,
                ]);

                return DeliveryReceipt::accepted($found['message_id'] !== null ? (string) $found['message_id'] : null);
            }
            $this->messages->finish($id, null, ['status' => 'retry']);

            return null;
        }

        $this->messages->finish($id, null, [
            'status' => 'failed', 'ambiguous' => 1, 'error_key' => 'marketing.transactional.error.unknown',
            'error' => 'Interrupted while sending; not retried automatically to avoid a duplicate.',
        ]);

        return DeliveryReceipt::failed('marketing.transactional.error.unknown');
    }

    /** null = the CURRENT organization can deliver on $key now; otherwise a translation key. */
    private function blockedReason(int $organizationId, string $key): ?string
    {
        $channel = $this->channels->get($key);
        if ($channel === null) {
            return 'marketing.error.invalid_channel';
        }
        if (!$this->entitlements->organizationHas($organizationId, MarketingModule::KEY) || !$this->entitlements->organizationHas($organizationId, $channel->moduleKey())) {
            return 'marketing.error.module_unavailable';
        }
        if ($key === 'email' && $this->emailProvider() === null) {
            return 'marketing.error.email_not_configured';
        }

        return $channel->unavailableReason($this->pseudoCampaign($key, 0));
    }

    /** The organization's first connected email provider with a sender identity (Sender or Brevo). */
    private function emailProvider(): ?string
    {
        foreach ($this->email->connectedProviders() as $provider) {
            if ($this->email->senderIdentity($provider) !== null) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The campaign-shaped array the channel interface expects. id 0 = no
     * campaign; sms_context labels the SMS log row.
     */
    private function pseudoCampaign(string $key, int $messageId): array
    {
        return [
            'id' => 0,
            'channel' => $key,
            'provider' => $key === 'email' ? $this->emailProvider() : null,
            'message_class' => 'transactional',
            'sms_context' => 'marketing.transactional:' . $messageId,
        ];
    }

    /** @return array{0: ?string, 1: string} [normalized address, or null with the error key] */
    private function address(string $key, string $raw): array
    {
        $phoneChannel = ChannelRegistry::phoneChannel($key);
        if ($phoneChannel !== null) {
            [$address, $reason] = $phoneChannel->classifyAddress($raw);
            if ($address === null) {
                return [null, $reason === 'unsupported_recipient' ? 'marketing.transactional.error.unsupported_recipient' : 'marketing.transactional.error.invalid_address'];
            }

            return [$address, ''];
        }
        $address = AudienceResolver::normalize($key, $raw);

        return [$address, $address === null ? 'marketing.transactional.error.invalid_address' : ''];
    }

    /** Plain text as simple HTML: escaped, links clickable, line breaks kept. */
    private function html(string $text, ?string $locale): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $linked = (string) preg_replace('~https?://[^\s<]+~', '<a href="$0">$0</a>', $escaped);
        $dir = in_array($locale, ['fa', 'ar'], true) ? 'rtl' : 'ltr';

        return '<div dir="' . $dir . '">' . nl2br($linked, false) . '</div>';
    }

    private function dedupeId(int $messageId): int
    {
        return $this->composer->dedupeId(self::DEDUPE_OFFSET + $messageId);
    }

    private function now(string $modify = ''): string
    {
        return date('Y-m-d H:i:s', $modify === '' ? time() : (int) strtotime($modify));
    }
}
