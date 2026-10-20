<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\SmsService;
use App\Application\Services\TimelineService;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\CampaignRepository;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\RecoverableChannel;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\SuppressionRepository;
use Modules\Marketing\TransactionalMessageRepository;

/**
 * The send queue. There is no job system on shared hosting, so the
 * queue is marketing_messages itself, processed in small batches by:
 *  - the cron worker (modules/Marketing/bin/worker.php, every minute) —
 *    every organization's active campaigns; and
 *  - the campaign page while someone watches it (a short JSON request
 *    every few seconds) — only that campaign.
 * Neither blocks the request that approved the campaign.
 *
 * Safety:
 *  - a row is claimed atomically (lock token) before sending, so two
 *    workers never send the same row; results are written only by the
 *    token holder;
 *  - SMS rows carry a provider dedupe id (Kavenegar localid), so even a
 *    row recovered after a crash cannot be sent twice;
 *  - an email whose request may have reached the provider (timeout,
 *    or a worker that died mid-send) is marked failed + ambiguous and is
 *    never retried automatically;
 *  - rate limits / outages (retryable) go back to the queue with a
 *    delay, at most MAX_ATTEMPTS times; a 429 stops the batch;
 *  - every campaign is processed inside its own OrganizationContext,
 *    and only while the organization still holds the channel's module.
 */
final class QueueWorker
{
    public const MAX_ATTEMPTS = 3;
    private const LOCK_MINUTES = 5;
    private const CLAIM_CHUNK = 10;
    private const REPORT_WINDOW_HOURS = 47; // Kavenegar keeps status for 48 hours

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly MessageRepository $messages,
        private readonly SuppressionRepository $suppressions,
        private readonly ChannelRegistry $channels,
        private readonly Entitlements $entitlements,
        private readonly MessageComposer $composer,
        private readonly SmsService $sms,
        private readonly TimelineService $timeline,
        private readonly LoggerInterface $logger,
        private readonly ?AudienceResolver $audience = null,
        private readonly ?TransactionalMessageRepository $transactional = null,
    ) {
    }

    /**
     * Cron entry point: every active campaign of every organization,
     * within a time budget, then delivery reports.
     *
     * @return array{campaigns: int, processed: int, reports: int}
     */
    public function runAll(int $timeBudgetSeconds = 50, int $perCampaign = 200): array
    {
        $deadline = microtime(true) + $timeBudgetSeconds;
        $stats = ['campaigns' => 0, 'processed' => 0, 'reports' => 0];
        $this->entitlements->reset();

        foreach ($this->campaigns->activeAcrossOrganizations() as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $stats['campaigns']++;
            try {
                $result = OrganizationContext::runAs((int) $row['organization_id'], fn () => $this->processCampaign((int) $row['id'], $perCampaign, $deadline));
                $stats['processed'] += $result['processed'];
            } catch (\Throwable $e) {
                $this->logger->error('Marketing worker: campaign ' . (int) $row['id'] . ' failed: ' . $e->getMessage());
            }
        }

        if (microtime(true) < $deadline) {
            $stats['reports'] = $this->pollAllDeliveryReports($deadline);
        }

        return $stats;
    }

    /**
     * Sends up to $limit due messages of one campaign of the CURRENT
     * organization.
     *
     * @return array{processed: int, remaining: int, status: string, error: ?string}
     */
    public function processCampaign(int $campaignId, int $limit, float $deadline): array
    {
        $campaign = $this->campaigns->findById($campaignId);
        if ($campaign === null) {
            return ['processed' => 0, 'remaining' => 0, 'status' => 'missing', 'error' => null];
        }
        $background = $campaign['status'] === 'preparing' && $campaign['prepare_cursor'] !== null;
        if (!$background && !in_array($campaign['status'], ['queued', 'sending'], true)) {
            return ['processed' => 0, 'remaining' => 0, 'status' => (string) $campaign['status'], 'error' => null];
        }

        $organizationId = OrganizationContext::id();
        $channel = $this->channels->get((string) $campaign['channel']);
        $blocked = match (true) {
            $channel === null => 'marketing.error.invalid_channel',
            !$this->entitlements->organizationHas($organizationId, 'marketing') || !$this->entitlements->organizationHas($organizationId, $channel->moduleKey()) => 'marketing.error.module_unavailable',
            default => $channel->unavailableReason($campaign),
        };
        if ($blocked !== null) {
            // Messages stay queued; sending resumes once the module/provider is back.
            if (($campaign['last_error'] ?? null) !== $blocked) {
                $this->campaigns->updateCampaign($campaignId, ['last_error' => $blocked]);
            }

            return ['processed' => 0, 'remaining' => $this->messages->pendingCount($campaignId), 'status' => (string) $campaign['status'], 'error' => $blocked];
        }
        if (!empty($campaign['last_error'])) {
            $this->campaigns->updateCampaign($campaignId, ['last_error' => null]);
        }

        if ($background) {
            $campaign = $this->expand($campaign, $deadline);
            if ($campaign['status'] !== 'queued') {
                return ['processed' => 0, 'remaining' => $this->messages->pendingCount($campaignId), 'status' => (string) $campaign['status'], 'error' => null];
            }
        }

        if ($campaign['status'] === 'queued' && $this->campaigns->transition($campaignId, ['queued'], 'sending', ['started_at' => $this->now()])) {
            $campaign['status'] = 'sending';
        }

        $this->recoverStale($campaign, $channel);

        if ($channel instanceof RecoverableChannel) {
            // The channel's own per-run cap (e.g. Bale: an internal safety setting).
            $limit = min($limit, $channel->maxPerRun());
        }

        $processed = 0;
        $stop = false;
        while (!$stop && $processed < $limit && microtime(true) < $deadline) {
            $token = bin2hex(random_bytes(12));
            $rows = $this->messages->claim($campaignId, min(self::CLAIM_CHUNK, $limit - $processed), $token, $this->now(), $this->now('+' . self::LOCK_MINUTES . ' minutes'));
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if ($stop) {
                    // Rate limited: hand the rest of the claim back untouched.
                    $this->messages->finish((int) $row['id'], $token, ['status' => 'pending', 'attempts' => max(0, (int) $row['attempts'] - 1)]);
                    continue;
                }
                $stop = $this->sendOne($campaign, $row, $token, $channel);
                $processed++;
            }
        }

        $remaining = $this->messages->pendingCount($campaignId);
        if ($remaining === 0 && $this->campaigns->transition($campaignId, ['sending'], 'completed', ['finished_at' => $this->now()])) {
            $counts = $this->messages->countsForCampaign($campaignId)['status'];
            $this->timeline->record('marketing_campaign', $campaignId, 'marketing.campaign_completed', sprintf(
                'Campaign completed: %d sent, %d failed, %d skipped',
                $counts['sent'] ?? 0, $counts['failed'] ?? 0, $counts['skipped'] ?? 0
            ), null);
            $campaign['status'] = 'completed';
        }

        return ['processed' => $processed, 'remaining' => $remaining, 'status' => (string) $campaign['status'], 'error' => null];
    }

    /**
     * Delivery reports for SMS sent in the last 47 hours, organization by
     * organization (Kavenegar sms/status.json, up to 500 ids per call) —
     * campaign messages and (Phase B) transactional messages.
     * Email delivery comes from provider webhooks instead.
     */
    public function pollAllDeliveryReports(float $deadline): int
    {
        $updated = 0;
        $since = $this->now('-' . self::REPORT_WINDOW_HOURS . ' hours');
        $organizations = array_unique(array_merge(
            $this->messages->organizationsWithSmsSince($since),
            $this->transactional?->organizationsWithSmsSince($since) ?? [],
        ));
        foreach ($organizations as $organizationId) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $updated += OrganizationContext::runAs($organizationId, fn () => $this->pollDeliveryReports());
            } catch (\Throwable $e) {
                $this->logger->error('Marketing worker: delivery reports for organization ' . $organizationId . ' failed: ' . $e->getMessage());
            }
        }

        return $updated;
    }

    /** Delivery reports for the CURRENT organization. Returns rows whose state changed. */
    public function pollDeliveryReports(int $limit = 500): int
    {
        $since = $this->now('-' . self::REPORT_WINDOW_HOURS . ' hours');
        $changed = $this->applySmsReports(
            $this->messages->awaitingSmsReport($since, $this->now('-5 minutes'), $limit),
            fn (int $id, array $fields) => $this->messages->updateDelivery($id, $fields),
        );
        if ($this->transactional !== null) {
            $changed += $this->applySmsReports(
                $this->transactional->awaitingSmsReport($since, $this->now('-5 minutes'), $limit),
                fn (int $id, array $fields) => $this->transactional->updateDelivery($id, $fields),
            );
        }

        return $changed;
    }

    /**
     * Asks each sending account for the state of its messages and stores
     * what it reports; "blocked" (the recipient refused promotional SMS at
     * the operator) also adds an SMS opt-out.
     *
     * @param \Closure(int, array): void $update
     */
    private function applySmsReports(array $rows, \Closure $update): int
    {
        if ($rows === []) {
            return 0;
        }

        $byProvider = [];
        foreach ($rows as $row) {
            $byProvider[(string) $row['provider']][] = $row;
        }

        $changed = 0;
        foreach ($byProvider as $provider => $providerRows) {
            $statuses = $this->sms->deliveryStatuses(array_column($providerRows, 'provider_message_id'), $provider);
            foreach ($providerRows as $row) {
                $status = $statuses[(string) $row['provider_message_id']] ?? null;
                $fields = ['status_checked_at' => $this->now()];
                if ($status !== null && $status['state'] !== 'unknown') {
                    $fields['delivery_status'] = $status['state'];
                    if ($status['state'] === 'delivered') {
                        $fields['delivered_at'] = $this->now();
                    }
                    if ($status['state'] === 'blocked') {
                        // The recipient opted out of promotional SMS at the operator.
                        $this->suppressions->add('sms', (string) $row['address'], 'blocked', 'delivery_report', $row['contact_id'] !== null ? (int) $row['contact_id'] : null, null);
                    }
                    $changed++;
                }
                $update((int) $row['id'], $fields);
            }
        }

        return $changed;
    }

    /**
     * F5: expands a large approved audience into recipient rows, one chunk
     * at a time from prepare_cursor (the last contact id done), until the
     * deadline. Resumes on the next run after a timeout or crash; INSERT
     * IGNORE + the UNIQUE index keep a repeated chunk from duplicating
     * anyone. Stops if the campaign was cancelled meanwhile. When the
     * audience is exhausted the campaign becomes 'queued'.
     */
    private function expand(array $campaign, float $deadline): array
    {
        if ($this->audience === null) {
            return $campaign;
        }
        $id = (int) $campaign['id'];
        $ids = array_values(array_map('intval', (array) json_decode((string) ($campaign['audience_ids'] ?? '[]'), true)));
        // Phase 2: a segment campaign expands the definition frozen at approval.
        $snapshot = !empty($campaign['audience_snapshot']) ? json_decode((string) $campaign['audience_snapshot'], true) : null;

        while (microtime(true) < $deadline) {
            $current = $this->campaigns->findById($id);
            if ($current === null || $current['status'] !== 'preparing') {
                if ($current !== null && $current['status'] === 'cancelled') {
                    $this->messages->cancelPending($id);
                }

                return $current ?? $campaign;
            }

            $result = $this->audience->walk(
                (string) $campaign['channel'],
                (string) $campaign['message_class'],
                (string) $campaign['audience_type'],
                $ids,
                fn (array $rows) => $this->messages->insertRecipients($id, (string) $campaign['channel'], array_map(fn ($r) => [
                    'contact_id' => $r['contact_id'],
                    'address' => $r['address'],
                    'recipient_name' => $r['recipient_name'],
                    'status' => $r['status'],
                    'skip_reason' => $r['skip_reason'],
                ], $rows)),
                (int) $current['prepare_cursor'],
                1,
                is_array($snapshot) ? $snapshot : null,
            );
            $this->campaigns->updateCampaign($id, ['prepare_cursor' => $result['last_id']]);

            if ($result['done']) {
                $total = $this->messages->pendingCount($id);
                $this->campaigns->transition($id, ['preparing'], 'queued', ['total_recipients' => $total]);
                $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_prepared', sprintf('Recipients prepared in the background: %d to send', $total), null);

                return $this->campaigns->findById($id) ?? $campaign;
            }
        }

        return $this->campaigns->findById($id) ?? $campaign;
    }

    /** @return bool true when the provider rate-limited us (stop this batch) */
    private function sendOne(array $campaign, array $row, string $token, \Modules\Marketing\Channels\MarketingChannelInterface $channel): bool
    {
        $id = (int) $row['id'];

        // Opt-outs that arrived after the campaign was queued still count.
        $reason = $this->suppressions->reasonsFor((string) $campaign['channel'], [(string) $row['address']])[(string) $row['address']] ?? null;
        if ($reason !== null && ($campaign['message_class'] === 'marketing' || $reason !== 'unsubscribed')) {
            $this->messages->finish($id, $token, ['status' => 'skipped', 'skip_reason' => 'suppressed_' . $reason]);

            return false;
        }

        try {
            $content = $this->composer->compose($campaign, $row);
            if (trim($content['text']) === '' && trim($content['html']) === '') {
                $this->messages->finish($id, $token, ['status' => 'failed', 'error' => 'Message is empty after personalization.']);

                return false;
            }
            $billed = $row['billed_units'] !== null ? (int) $row['billed_units'] : null;
            $result = $channel->send(
                $campaign, (string) $row['address'], (string) ($row['recipient_name'] ?? ''), $content, $this->composer->dedupeId($id),
                $billed,
                // Stored the moment the charge happens, so a crash right after it can't lead to a second charge.
                fn (?int $units) => $this->messages->setBilledUnits($id, $token, $units),
            );
        } catch (\Throwable $e) {
            // Unknown state: never retried automatically for email; SMS is dedupe-protected.
            $this->logger->error('Marketing worker: message ' . $id . ' threw: ' . $e->getMessage());
            $this->messages->finish($id, $token, ['status' => 'failed', 'error' => 'Internal error while sending.', 'ambiguous' => $channel instanceof RecoverableChannel ? ($channel->exceptionIsAmbiguous() ? 1 : 0) : ($campaign['channel'] === 'sms' ? 0 : 1)]);

            return false;
        }

        if ($result->success) {
            $this->messages->finish($id, $token, [
                'status' => 'sent',
                'provider' => mb_substr($result->provider, 0, 40),
                'provider_message_id' => $result->providerMessageId !== null ? mb_substr($result->providerMessageId, 0, 255) : null,
                'sent_at' => $this->now(),
                'error' => null,
                'next_attempt_at' => null,
            ]);

            return false;
        }

        $error = mb_substr((string) $result->error, 0, 500);
        if ($result->skipReason !== null) {
            // The channel cannot serve this recipient (e.g. no Bale account): skipped, not failed, never retried.
            $this->messages->finish($id, $token, ['status' => 'skipped', 'skip_reason' => mb_substr($result->skipReason, 0, 40), 'provider' => mb_substr($result->provider, 0, 40), 'error' => $error]);

            return false;
        }
        if ($result->pauseReason !== null) {
            // Nothing was sent and the account cannot send for now: back to the queue without using
            // an attempt, stop the batch, and show why on the campaign.
            $delay = max(1, $result->retryAfterSeconds ?? 600);
            $this->messages->finish($id, $token, ['status' => 'pending', 'attempts' => max(0, (int) $row['attempts'] - 1), 'provider' => mb_substr($result->provider, 0, 40), 'error' => $error, 'next_attempt_at' => $this->now('+' . $delay . ' seconds')]);
            $this->campaigns->updateCampaign((int) $campaign['id'], ['last_error' => mb_substr($result->pauseReason, 0, 255)]);

            return true;
        }
        if ($result->retryable && (int) $row['attempts'] < self::MAX_ATTEMPTS) {
            $delay = $result->retryAfterSeconds ?? (60 * max(1, (int) $row['attempts']));
            $this->messages->finish($id, $token, ['status' => 'pending', 'provider' => mb_substr($result->provider, 0, 40), 'error' => $error, 'next_attempt_at' => $this->now('+' . $delay . ' seconds')]);

            return $result->stopBatch || str_contains($error, 'HTTP 429');
        }

        $this->messages->finish($id, $token, [
            'status' => 'failed',
            'provider' => mb_substr($result->provider, 0, 40),
            'error' => $error,
            'ambiguous' => $result->ambiguous ? 1 : 0,
        ]);

        return false;
    }

    /**
     * Rows left in "processing" by a worker that died.
     *
     * SMS: first ask the provider whether it already has a message with
     * this row's id (Kavenegar statuslocalmessageid). Found → recorded as
     * sent, no resend and no new charge. Not found, or the provider can't
     * be asked → back to the queue; Kavenegar's localid still blocks a
     * second SMS, and billed_units blocks a second charge.
     *
     * Email: marked failed + ambiguous — it may already have gone out.
     */
    private function recoverStale(array $campaign, ?\Modules\Marketing\Channels\MarketingChannelInterface $channel = null): void
    {
        foreach ($this->messages->staleProcessing((int) $campaign['id'], $this->now()) as $row) {
            if ($channel instanceof RecoverableChannel) {
                // The channel decides (e.g. Bale: no status query → unknown, never resent automatically).
                $fields = $channel->recoverStale($campaign, $row, $this->composer->dedupeId((int) $row['id']));
                $this->messages->finish((int) $row['id'], (string) $row['lock_token'], $fields);
                continue;
            }
            if ($campaign['channel'] === 'sms') {
                $provider = $this->sms->currentSenderName();
                $found = $this->sms->findByLocalId($this->composer->dedupeId((int) $row['id']), $provider);
                if ($found !== null && $found['found']) {
                    $this->messages->finish((int) $row['id'], (string) $row['lock_token'], [
                        'status' => 'sent',
                        'provider' => mb_substr($provider, 0, 40),
                        'provider_message_id' => $found['message_id'],
                        'delivery_status' => in_array($found['state'], ['delivered', 'undelivered', 'failed', 'blocked'], true) ? $found['state'] : null,
                        'sent_at' => $this->now(),
                        'error' => null,
                    ]);
                    continue;
                }
                $this->messages->finish((int) $row['id'], (string) $row['lock_token'], ['status' => 'pending']);
            } else {
                $this->messages->finish((int) $row['id'], (string) $row['lock_token'], ['status' => 'failed', 'ambiguous' => 1, 'error' => 'Interrupted while sending; not retried automatically to avoid a duplicate.']);
            }
        }
    }

    private function now(string $modify = ''): string
    {
        return date('Y-m-d H:i:s', $modify === '' ? time() : (int) strtotime($modify));
    }
}
