<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Time\OrganizationTimezone;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Security\ActionRateLimiter;
use Modules\Marketing\Segments\SegmentAudience;
use App\Infrastructure\Modules\ModuleGate;
use Modules\Marketing\CampaignRepository;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\Channels\RecoverableChannel;
use Modules\Marketing\Channels\DispatchResult;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\MessageRepository;

/**
 * Campaign use cases. Every method re-checks the permission (and, for
 * edits, own/team scope through owner_id) — routes are only a first gate.
 *
 * Lifecycle: draft (editable) → [marketing.send approves] preparing
 * (recipients being snapshotted; the worker ignores it) → queued →
 * sending → completed; cancellable while preparing/queued/sending.
 * Recipients are snapshotted once at approval; later contact changes
 * don't alter a queued campaign, but new opt-outs are honoured at send.
 */
final class CampaignService
{
    public const MESSAGE_CLASSES = ['marketing', 'transactional'];
    public const SMS_MAX_LENGTH = 900;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly MessageRepository $messages,
        private readonly AudienceResolver $audience,
        private readonly MessageComposer $composer,
        private readonly ChannelRegistry $channels,
        private readonly AuthorizationEngine $authorization,
        private readonly ModuleGate $gate,
        private readonly TimelineService $timeline,
        private readonly EmailProviderService $email,
        private readonly ActionRateLimiter $limiter,
        private readonly int $syncAudienceLimit = 2000,
        private readonly int $transactionalMaxRecipients = 50,
        private readonly ?SegmentAudience $segments = null,
    ) {
    }

    /**
     * Marketing 3.1 (F1): Marketing never shows contact data (names,
     * numbers, emails) to someone who may not view contacts. Without
     * contacts.view, previews use placeholders and lists are masked.
     */
    public function canSeeContacts(array $user): bool
    {
        return $this->authorization->can($user, Permission::CONTACTS_VIEW);
    }

    // --- Reading -------------------------------------------------------------

    public function page(array $user, array $filters, int $limit, int $offset): array
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->campaigns->page($filters, $limit, $offset);
    }

    public function count(array $user, array $filters): int
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->campaigns->count($filters);
    }

    public function find(array $user, int $id): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $campaign = $this->campaigns->findById($id);
        if ($campaign === null) {
            throw new NotFoundException('Campaign not found.');
        }

        return $campaign;
    }

    public function canEdit(array $user, array $campaign): bool
    {
        return $campaign['status'] === 'draft' && $this->authorization->can($user, MarketingPermission::CREATE, $campaign);
    }

    public function canSend(array $user): bool
    {
        return $this->authorization->can($user, MarketingPermission::SEND);
    }

    /** For templates: whether to show an action (the action re-checks). */
    public function hasPermission(array $user, string $permission): bool
    {
        return in_array($permission, MarketingPermission::ALL, true) && $this->authorization->can($user, $permission);
    }

    /** Channels this user's organization may use, in display order. */
    public function availableChannels(array $user): array
    {
        $keys = [];
        foreach ($this->channels->keys() as $key) {
            if ($this->gate->allows($user, $this->channels->get($key)->moduleKey(), null)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Everything the review page shows before approval: audience totals,
     * a personalized preview for the first eligible recipient, warnings.
     *
     * Large audiences (above messaging.marketing.sync_audience_limit,
     * F5) are not walked here: the total is one COUNT and the sample comes
     * from the first chunk; exact counts appear as the worker expands it.
     *
     * @return array{totals: array, preview: array, warnings: string[], channel_error: ?string, sms_parts: int, large: bool, masked: bool, confirm_count: int}
     */
    public function review(array $user, array $campaign): array
    {
        $this->authorize($user, MarketingPermission::VIEW);
        $ids = $this->audienceIds($campaign);
        $matching = $this->audience->count((string) $campaign['audience_type'], $ids);
        $large = $matching > $this->syncAudienceLimit;
        $totals = $this->audience->walk((string) $campaign['channel'], (string) $campaign['message_class'], (string) $campaign['audience_type'], $ids, null, 0, $large ? 1 : null);
        if ($large) {
            $totals['contacts'] = $matching;
        }
        $masked = !$this->canSeeContacts($user);
        $sample = $masked ? null : $totals['sample'];
        if ($masked) {
            $totals['sample'] = null;
        }
        $preview = $this->composer->preview($campaign, $sample, $masked);

        $warnings = [];
        foreach (['missing_address', 'invalid_address', 'unsupported', 'suppressed', 'duplicate'] as $key) {
            if (!isset($totals[$key])) {
                continue;
            }
            if ($totals[$key] > 0) {
                $warnings[] = 'marketing.warning.' . $key;
            }
        }
        if ($totals['eligible'] === 0) {
            $warnings[] = 'marketing.warning.no_eligible';
        }
        if ($large) {
            $warnings = ['marketing.warning.large_audience'];
        }
        $classError = $this->transactionalError($user, $campaign['message_class'], (string) $campaign['audience_type'], $ids);

        return [
            'totals' => $totals,
            'preview' => $preview,
            'warnings' => $warnings,
            'channel_error' => $this->channelError($user, $campaign) ?? $classError,
            'sms_parts' => $campaign['channel'] === 'sms' ? SmsService::partsFor($preview['text']) : 0,
            'large' => $large,
            'masked' => $masked,
            // What the approver confirms: eligible recipients, or (large) matching contacts.
            'confirm_count' => $large ? $matching : $totals['eligible'],
        ];
    }

    // --- Drafts --------------------------------------------------------------

    /** @return int new campaign id */
    public function create(array $user, array $input): int
    {
        $this->authorize($user, MarketingPermission::CREATE);
        $data = $this->validated($user, $input);
        $id = $this->campaigns->create($data + ['status' => 'draft', 'owner_id' => (int) $user['id']]);
        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_created', 'Campaign created: ' . mb_substr($data['name'], 0, 200), (int) $user['id']);

        return $id;
    }

    public function update(array $user, int $id, array $input): void
    {
        $campaign = $this->find($user, $id);
        if (!$this->canEdit($user, $campaign)) {
            throw new AuthorizationException();
        }

        $data = $this->validated($user, $input);
        $this->campaigns->updateCampaign($id, $data);
        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_updated', 'Campaign edited', (int) $user['id']);
    }

    public function delete(array $user, int $id): void
    {
        $campaign = $this->find($user, $id);
        if (!$this->canEdit($user, $campaign)) {
            throw new AuthorizationException();
        }

        $this->campaigns->deleteDraft($id);
    }

    // --- Approval and sending ------------------------------------------------

    /**
     * Approves a draft and queues it. Re-validates entitlement, provider
     * configuration and audience; the recipient count the user confirmed
     * must still be right, and "all contacts" needs explicit confirmation.
     *
     * Phase D: with $sendDate/$sendTime (the organization's local date and
     * time) the campaign is approved and queued the same way — recipients
     * frozen now — but the worker starts sending only at that moment.
     * Opt-outs, entitlement and the provider are checked again at send time.
     *
     * @return int recipients queued
     */
    public function approveAndQueue(array $user, int $id, int $confirmedCount, bool $confirmedAll, bool $confirmedConsent = false, ?string $sendDate = null, ?string $sendTime = null): int
    {
        $this->authorize($user, MarketingPermission::SEND);
        $campaign = $this->find($user, $id);
        if ($campaign['status'] !== 'draft') {
            throw new ValidationException(['campaign' => ['marketing.error.not_draft']]);
        }
        $scheduledAt = $this->scheduledAt($sendDate, $sendTime);

        $channelError = $this->channelError($user, $campaign);
        if ($channelError !== null) {
            throw new ValidationException(['campaign' => [$channelError]]);
        }
        // Phone-text channels added by modules (e.g. Bale): explicit consent confirmation + an approval rate limit.
        $phoneChannel = ChannelRegistry::phoneChannel((string) $campaign['channel']);
        if ($phoneChannel !== null) {
            if ($phoneChannel->requiresConsentConfirmation() && !$confirmedConsent) {
                throw new ValidationException(['confirm_consent' => ['marketing.error.confirm_consent']]);
            }
            $perHour = $phoneChannel->approvalsPerUserPerHour();
            if ($perHour > 0 && !$this->limiter->attempt('marketing.approve.' . $campaign['channel'], [['subject' => 'user:' . (int) $user['id'], 'max' => $perHour, 'window' => 3600]])) {
                throw new ValidationException(['campaign' => ['marketing.error.approve_rate_limited']]);
            }
        }
        $audienceError = $this->audience->validate((string) $campaign['audience_type'], $this->audienceIds($campaign));
        if ($audienceError !== null) {
            throw new ValidationException(['audience' => [$audienceError]]);
        }
        if ($campaign['audience_type'] === 'all' && !$confirmedAll) {
            throw new ValidationException(['confirm_all' => ['marketing.error.confirm_all']]);
        }
        $classError = $this->transactionalError($user, (string) $campaign['message_class'], (string) $campaign['audience_type'], $this->audienceIds($campaign));
        if ($classError !== null) {
            throw new ValidationException(['message_class' => [$classError]]);
        }

        // Phase 2: a segment is re-resolved NOW and frozen for this campaign (rules, or static members as of now).
        $snapshot = null;
        if ($campaign['audience_type'] === 'segment') {
            $snapshot = $this->segments?->snapshot((int) ($this->audienceIds($campaign)[0] ?? 0));
            if ($snapshot === null) {
                throw new ValidationException(['audience' => ['marketing.error.segment_missing']]);
            }
        }
        $snapshotJson = $snapshot !== null ? (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;

        // F5: a large audience is expanded by the worker, not in this request.
        $matching = $this->audience->count((string) $campaign['audience_type'], $this->audienceIds($campaign), $snapshot);
        if ($matching > $this->syncAudienceLimit) {
            if ($confirmedCount !== $matching) {
                throw new ValidationException(['audience' => ['marketing.error.count_changed']]);
            }
            if (!$this->campaigns->transition($id, ['draft'], 'preparing', ['queued_by_user_id' => (int) $user['id'], 'prepare_cursor' => 0, 'queued_at' => date('Y-m-d H:i:s'), 'audience_snapshot' => $snapshotJson, 'scheduled_at' => $scheduledAt])) {
                throw new ValidationException(['campaign' => ['marketing.error.not_draft']]);
            }
            $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_queued', sprintf('Campaign approved; %d contacts are being prepared in the background', $matching) . ($scheduledAt !== null ? '; scheduled for ' . $scheduledAt . ' UTC' : ''), (int) $user['id']);

            return $matching;
        }

        $totals = $this->audience->walk((string) $campaign['channel'], (string) $campaign['message_class'], (string) $campaign['audience_type'], $this->audienceIds($campaign), null, 0, null, $snapshot);
        if ($totals['eligible'] === 0) {
            throw new ValidationException(['audience' => ['marketing.warning.no_eligible']]);
        }
        if ($confirmedCount !== $totals['eligible']) {
            throw new ValidationException(['audience' => ['marketing.error.count_changed']]);
        }

        // Claim the draft first: a second click / second approver loses here.
        if (!$this->campaigns->transition($id, ['draft'], 'preparing', ['queued_by_user_id' => (int) $user['id'], 'audience_snapshot' => $snapshotJson, 'scheduled_at' => $scheduledAt])) {
            throw new ValidationException(['campaign' => ['marketing.error.not_draft']]);
        }

        try {
            $queued = $this->messages->atomically(function () use ($campaign, $id, $snapshot): int {
                $eligible = 0;
                $this->audience->walk((string) $campaign['channel'], (string) $campaign['message_class'], (string) $campaign['audience_type'], $this->audienceIds($campaign), function (array $rows) use ($id, $campaign, &$eligible): void {
                    $this->messages->insertRecipients($id, (string) $campaign['channel'], array_map(fn ($r) => [
                        'contact_id' => $r['contact_id'],
                        'address' => $r['address'],
                        'recipient_name' => $r['recipient_name'],
                        'status' => $r['status'],
                        'skip_reason' => $r['skip_reason'],
                    ], $rows));
                    $eligible += count(array_filter($rows, fn ($r) => $r['status'] === 'pending'));
                }, 0, null, $snapshot);
                $this->campaigns->transition($id, ['preparing'], 'queued', ['total_recipients' => $eligible, 'queued_at' => date('Y-m-d H:i:s')]);

                return $eligible;
            });
        } catch (\Throwable $e) {
            $this->messages->deleteForCampaign($id);
            $this->campaigns->transition($id, ['preparing'], 'draft', ['audience_snapshot' => null, 'scheduled_at' => null]);
            throw $e;
        }

        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_queued', sprintf('Campaign approved and queued for %d recipients', $queued) . ($scheduledAt !== null ? '; scheduled for ' . $scheduledAt . ' UTC' : ''), (int) $user['id']);

        return $queued;
    }

    /** Whether a queued campaign is still waiting for its scheduled time. */
    public static function isScheduled(array $campaign): bool
    {
        return in_array($campaign['status'] ?? '', ['preparing', 'queued'], true)
            && !empty($campaign['scheduled_at']) && (string) $campaign['scheduled_at'] > date('Y-m-d H:i:s');
    }

    /**
     * The send time as stored UTC, from the organization's local date + time;
     * null = send now. At least a minute ahead, at most a year.
     */
    private function scheduledAt(?string $date, ?string $time): ?string
    {
        $date = trim((string) $date);
        $time = trim((string) $time);
        if ($date === '' && $time === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ValidationException(['send_date' => ['marketing.error.schedule_date']]);
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new ValidationException(['send_time' => ['marketing.error.schedule_time']]);
        }
        $utc = OrganizationTimezone::shared()->toStorage($date . ' ' . $time . ':00');
        if ($utc <= date('Y-m-d H:i:s', time() + 60)) {
            throw new ValidationException(['send_date' => ['marketing.error.schedule_past']]);
        }
        if ($utc > date('Y-m-d H:i:s', time() + 366 * 86400)) {
            throw new ValidationException(['send_date' => ['marketing.error.schedule_far']]);
        }

        return $utc;
    }

    public function cancel(array $user, int $id): int
    {
        $this->authorize($user, MarketingPermission::SEND);
        $this->find($user, $id);

        if (!$this->campaigns->transition($id, ['preparing', 'queued', 'sending'], 'cancelled', ['cancelled_at' => date('Y-m-d H:i:s')])) {
            throw new ValidationException(['campaign' => ['marketing.error.not_cancellable']]);
        }
        $cancelled = $this->messages->cancelPending($id);
        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_cancelled', sprintf('Campaign cancelled; %d queued messages cancelled', $cancelled), (int) $user['id']);

        return $cancelled;
    }

    /**
     * Puts failed messages that certainly did not reach the provider back
     * in the queue. "Ambiguous" failures (may have been sent) are never
     * requeued, so a retry cannot duplicate a message.
     */
    public function retryFailed(array $user, int $id): int
    {
        $this->authorize($user, MarketingPermission::SEND);
        $campaign = $this->find($user, $id);
        if (!in_array($campaign['status'], ['sending', 'completed'], true)) {
            throw new ValidationException(['campaign' => ['marketing.error.not_retryable']]);
        }

        $requeued = $this->messages->requeueFailed($id);
        if ($requeued > 0) {
            $this->campaigns->transition($id, ['completed'], 'sending', ['finished_at' => null]);
            $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_retry', sprintf('%d failed messages queued again', $requeued), (int) $user['id']);
        }

        return $requeued;
    }

    /**
     * Puts ONE message with an unknown outcome (failed + ambiguous: it may
     * already have reached the provider) back in the queue — only on
     * channels that resend with the same provider dedupe id (e.g. Bale's
     * request_id), only by marketing.send, and only after the user
     * explicitly confirmed the possible duplicate. Never automatic.
     */
    public function retryUnknown(array $user, int $id, int $messageId, bool $confirmed): void
    {
        $this->authorize($user, MarketingPermission::SEND);
        $campaign = $this->find($user, $id);
        $channel = $this->channels->get((string) $campaign['channel']);
        if (!$channel instanceof RecoverableChannel || !$channel->allowsConfirmedResend()) {
            throw new ValidationException(['campaign' => ['marketing.error.not_retryable']]);
        }
        if (!in_array($campaign['status'], ['sending', 'completed'], true)) {
            throw new ValidationException(['campaign' => ['marketing.error.not_retryable']]);
        }
        if (!$confirmed) {
            throw new ValidationException(['campaign' => ['marketing.error.confirm_unknown_retry']]);
        }
        $channelError = $this->channelError($user, $campaign);
        if ($channelError !== null) {
            throw new ValidationException(['campaign' => [$channelError]]);
        }
        if (!$this->messages->requeueUnknown($id, $messageId)) {
            throw new ValidationException(['campaign' => ['marketing.error.not_retryable']]);
        }
        $this->campaigns->transition($id, ['completed'], 'sending', ['finished_at' => null]);
        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_retry_unknown', sprintf('Message #%d with an unknown outcome queued again after confirmation (same provider request id)', $messageId), (int) $user['id']);
    }

    /** Whether a campaign's channel allows the confirmed resend of unknown outcomes (for templates). */
    public function allowsUnknownRetry(array $campaign): bool
    {
        $channel = $this->channels->get((string) $campaign['channel']);

        return $channel instanceof RecoverableChannel && $channel->allowsConfirmedResend();
    }

    /** Whether approving this campaign needs the consent confirmation checkbox. */
    public function requiresConsentConfirmation(array $campaign): bool
    {
        return ChannelRegistry::phoneChannel((string) $campaign['channel'])?->requiresConsentConfirmation() ?? false;
    }

    /**
     * A real test message to one address the user typed, personalized
     * with the first eligible recipient's data. Never stored as a
     * campaign recipient; audited on the campaign.
     */
    public function testSend(array $user, int $id, string $address): DispatchResult
    {
        $campaign = $this->find($user, $id);
        $this->authorize($user, MarketingPermission::CREATE);

        $channelError = $this->channelError($user, $campaign);
        if ($channelError !== null) {
            throw new ValidationException(['test' => [$channelError]]);
        }
        $normalized = AudienceResolver::normalize((string) $campaign['channel'], $address);
        if ($normalized === null) {
            throw new ValidationException(['test' => ['marketing.error.test_address']]);
        }
        // F6: shared limit for every real test message (config messaging.test_sends).
        if (!$this->limiter->allowTestSend($user)) {
            throw new ValidationException(['test' => ['email_providers.error_test_rate_limited']]);
        }

        // F1: the test goes to an address the user typed — real contact data only for users who may view contacts.
        $masked = !$this->canSeeContacts($user);
        $sample = null;
        if (!$masked) {
            $totals = $this->audience->walk((string) $campaign['channel'], (string) $campaign['message_class'], (string) $campaign['audience_type'], $this->audienceIds($campaign), null, 0, 1);
            $sample = $totals['sample'];
        }
        $content = $this->composer->preview($campaign, $sample, $masked);
        if (($campaign['channel'] === 'email') && $content['unsubscribe_url'] === '#') {
            $content['unsubscribe_url'] = '';
        }
        if ($campaign['channel'] === 'email') {
            $content['subject'] = '[TEST] ' . $content['subject'];
        }

        $result = $this->channels->get((string) $campaign['channel'])->send($campaign, $normalized, '', $content, random_int(1, 999_999_999));
        $this->timeline->record('marketing_campaign', $id, 'marketing.campaign_test', ($result->success ? 'Test message sent to ' : 'Test message failed for ') . $this->mask($normalized), (int) $user['id']);

        return $result;
    }

    /** @return array{status: array<string, int>, delivery: array<string, int>, total: int} */
    public function counts(array $user, int $id): array
    {
        $this->find($user, $id);

        return $this->messages->countsForCampaign($id);
    }

    /** @return int[] */
    public function audienceIds(array $campaign): array
    {
        return array_values(array_map('intval', (array) json_decode((string) ($campaign['audience_ids'] ?? '[]'), true)));
    }

    // --- Internals -----------------------------------------------------------

    /** Module entitlement + provider configuration for this campaign's channel. */
    private function channelError(array $user, array $campaign): ?string
    {
        $channel = $this->channels->get((string) $campaign['channel']);
        if ($channel === null) {
            return 'marketing.error.invalid_channel';
        }
        if (!$this->gate->allows($user, $channel->moduleKey(), null)) {
            return 'marketing.error.module_unavailable';
        }

        return $channel->unavailableReason($campaign);
    }

    /** @return array<string, mixed> columns to store */
    private function validated(array $user, array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $channel = (string) ($input['channel'] ?? '');
        $class = (string) ($input['message_class'] ?? 'marketing');
        $type = (string) ($input['audience_type'] ?? '');

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'][] = 'marketing.error.name_required';
        }
        if (!in_array($channel, $this->availableChannels($user), true)) {
            $errors['channel'][] = 'marketing.error.invalid_channel';
        }
        if (!in_array($class, self::MESSAGE_CLASSES, true)) {
            $errors['message_class'][] = 'marketing.error.invalid_class';
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        $text = trim(str_replace("\r\n", "\n", (string) ($input['body_text'] ?? '')));
        $html = trim((string) ($input['body_html'] ?? ''));
        $provider = null;

        if ($channel === 'sms') {
            $subject = '';
            $html = '';
            if ($text === '') {
                $errors['body_text'][] = 'marketing.error.body_required';
            } elseif (mb_strlen($text) > self::SMS_MAX_LENGTH) {
                $errors['body_text'][] = 'marketing.error.sms_too_long';
            } elseif (Personalizer::uses($text, 'unsubscribe_url')) {
                $errors['body_text'][] = 'marketing.error.sms_no_unsubscribe_var';
            }
        } elseif (($phoneChannel = ChannelRegistry::phoneChannel($channel)) !== null) {
            $subject = '';
            $html = '';
            if ($text === '') {
                $errors['body_text'][] = 'marketing.error.body_required';
            } elseif (mb_strlen($text) > $phoneChannel->maxTextLength()) {
                $errors['body_text'][] = 'marketing.error.text_too_long';
            } elseif (Personalizer::uses($text, 'unsubscribe_url')) {
                $errors['body_text'][] = 'marketing.error.sms_no_unsubscribe_var';
            }
        } elseif ($channel === 'email') {
            $provider = (string) ($input['provider'] ?? '');
            if (!in_array($provider, $this->email->connectedProviders(), true)) {
                $errors['provider'][] = 'marketing.error.email_not_configured';
            }
            if ($subject === '' || mb_strlen($subject) > 255) {
                $errors['subject'][] = 'marketing.error.subject_required';
            }
            if ($html === '') {
                $errors['body_html'][] = 'marketing.error.body_required';
            } elseif (strlen($html) > 500_000) {
                $errors['body_html'][] = 'marketing.error.body_too_long';
            }
        }

        $unknown = Personalizer::unknownVariables($subject, $text, $html);
        if ($unknown !== []) {
            $errors['body_text'][] = 'marketing.error.unknown_variable';
        }

        $ids = match ($type) {
            'contacts' => array_map('intval', (array) ($input['contact_ids'] ?? [])),
            'group' => [(int) ($input['group_id'] ?? 0)],
            'tag' => [(int) ($input['tag_id'] ?? 0)],
            'segment' => [(int) ($input['segment_id'] ?? 0)],
            default => [],
        };
        $ids = array_values(array_unique(array_filter($ids, fn ($i) => $i > 0)));
        if ($type === 'contacts' && !$this->canSeeContacts($user)) {
            // Picking individual contacts means seeing them (F1).
            $errors['audience'][] = 'marketing.error.contacts_permission';
        } else {
            $audienceError = in_array($type, AudienceResolver::TYPES, true) ? $this->audience->validate($type, $ids) : 'marketing.error.invalid_audience';
            if ($audienceError !== null) {
                $errors['audience'][] = $audienceError;
            }
        }
        $classError = in_array($class, self::MESSAGE_CLASSES, true) ? $this->transactionalError($user, $class, $type, $ids) : null;
        if ($classError !== null) {
            $errors['message_class'][] = $classError;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'channel' => $channel,
            'provider' => $provider,
            'message_class' => $class,
            'subject' => $subject !== '' ? $subject : null,
            'body_text' => $text !== '' ? $text : null,
            'body_html' => $html !== '' ? $html : null,
            'audience_type' => $type,
            'audience_ids' => json_encode($ids),
        ];
    }

    /**
     * F2: "transactional" skips marketing opt-outs, so it is only for real
     * service messages: it needs marketing.send_transactional (org_admin
     * by default) and individually selected contacts, at most
     * messaging.marketing.transactional_max_recipients of them — never a
     * group, tag or everyone. Checked when saving AND when approving.
     */
    private function transactionalError(array $user, string $class, string $type, array $ids): ?string
    {
        if ($class !== 'transactional') {
            return null;
        }
        if (!$this->authorization->can($user, MarketingPermission::SEND_TRANSACTIONAL)) {
            return 'marketing.error.transactional_permission';
        }
        if ($type !== 'contacts' || count($ids) > $this->transactionalMaxRecipients) {
            return 'marketing.error.transactional_audience';
        }

        return null;
    }

    private function mask(string $address): string
    {
        return str_contains($address, '@')
            ? substr($address, 0, 2) . '***' . substr($address, (int) strpos($address, '@'))
            : substr($address, 0, 4) . '***' . substr($address, -2);
    }

    private function authorize(array $user, string $permission): void
    {
        if (!$this->authorization->can($user, $permission)) {
            throw new AuthorizationException();
        }
    }
}
