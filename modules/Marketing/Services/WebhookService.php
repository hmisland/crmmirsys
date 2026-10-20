<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\EmailProviderService;
use App\Application\Services\TimelineService;
use App\Infrastructure\Email\BrevoEmailProvider;
use App\Infrastructure\Email\BrevoWebhookParser;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\SuppressionRepository;
use Modules\Marketing\TransactionalMessageRepository;
use Modules\Marketing\WebhookEventRepository;

/**
 * Provider delivery events (Brevo transactional webhooks).
 *
 *  1. Authenticity: the URL key picks the organization and the request's
 *     "Authorization: Bearer" token must equal that organization's stored
 *     webhook secret (constant-time) — otherwise 401, nothing read.
 *  2. Entitlement: ignored (but acknowledged) when the organization no
 *     longer has the marketing email module.
 *  3. Idempotency: each event is recorded once (UNIQUE event key); a
 *     replay changes nothing.
 *  4. Scope: messages are looked up inside that organization only.
 *
 * Final states are never downgraded (a late "deferred" doesn't undo
 * "delivered"); bounce, complaint and unsubscribe add a suppression.
 * Events for transactional messages (Phase B, e.g. survey invitations)
 * update marketing_transactional_messages the same way.
 */
final class WebhookService
{
    /** Higher rank wins; equal rank: the newer event wins. */
    private const RANK = [
        'deferred' => 1, 'soft_bounce' => 2, 'delivered' => 3, 'blocked' => 4, 'invalid' => 4,
        'hard_bounce' => 4, 'unsubscribed' => 5, 'complaint' => 5,
    ];

    private const STORED = [
        'hard_bounce' => 'bounced', 'soft_bounce' => 'soft_bounced', 'delivered' => 'delivered', 'blocked' => 'blocked',
        'invalid' => 'invalid', 'deferred' => 'deferred', 'unsubscribed' => 'unsubscribed', 'complaint' => 'complaint',
    ];

    private const SUPPRESS = ['hard_bounce' => 'bounced', 'invalid' => 'bounced', 'complaint' => 'complaint', 'unsubscribed' => 'unsubscribed', 'blocked' => 'blocked'];

    public function __construct(
        private readonly EmailProviderService $email,
        private readonly MessageRepository $messages,
        private readonly SuppressionRepository $suppressions,
        private readonly WebhookEventRepository $events,
        private readonly Entitlements $entitlements,
        private readonly TimelineService $timeline,
        private readonly ?TransactionalMessageRepository $transactional = null,
    ) {
    }

    /** @return array{status: int, processed: int} HTTP status to answer and events applied */
    public function handle(string $provider, string $key, string $authorization, string $body): array
    {
        if ($provider !== BrevoEmailProvider::KEY) {
            return ['status' => 404, 'processed' => 0];
        }

        $organizationId = $this->email->authenticateWebhook($provider, $key, $authorization);
        if ($organizationId === null) {
            return ['status' => 401, 'processed' => 0];
        }
        if (strlen($body) > 1_000_000) {
            return ['status' => 413, 'processed' => 0];
        }
        if (!$this->entitlements->organizationHas($organizationId, 'marketing_email')) {
            return ['status' => 200, 'processed' => 0];
        }

        $processed = OrganizationContext::runAs($organizationId, function () use ($provider, $body): int {
            $count = 0;
            foreach (BrevoWebhookParser::parse($body) as $event) {
                if ($this->apply($provider, $event)) {
                    $count++;
                }
            }

            return $count;
        });

        return ['status' => 200, 'processed' => $processed];
    }

    /** @param array{key: string, type: string, message_id: string, email: string, occurred_at: string, reason: string} $event */
    private function apply(string $provider, array $event): bool
    {
        if (!$this->events->recordOnce($provider, $event['key'], $event['type'], $event['message_id'])) {
            return false; // already processed
        }

        $message = $this->messages->findByProviderMessageId($provider, $event['message_id']);
        $update = fn (int $id, array $fields) => $this->messages->updateDelivery($id, $fields);
        if ($message === null && $this->transactional !== null) {
            $message = $this->transactional->findByProviderMessageId($provider, $event['message_id']);
            $update = fn (int $id, array $fields) => $this->transactional->updateDelivery($id, $fields);
        }
        if ($message === null) {
            return false; // not one of our campaign or transactional messages (e.g. a test email)
        }

        $current = array_search((string) $message['delivery_status'], self::STORED, true);
        $currentRank = $current === false ? 0 : self::RANK[$current];
        if (self::RANK[$event['type']] >= $currentRank) {
            $fields = ['delivery_status' => self::STORED[$event['type']]];
            if ($event['type'] === 'delivered') {
                $fields['delivered_at'] = $event['occurred_at'];
            }
            if ($event['reason'] !== '' && $event['type'] !== 'delivered') {
                $fields['error'] = mb_substr($event['reason'], 0, 500);
            }
            $update((int) $message['id'], $fields);
        }

        if (isset(self::SUPPRESS[$event['type']])) {
            $contactId = $message['contact_id'] !== null ? (int) $message['contact_id'] : null;
            $this->suppressions->add('email', (string) $message['address'], self::SUPPRESS[$event['type']], 'webhook', $contactId, null);
            if ($contactId !== null && in_array($event['type'], ['unsubscribed', 'complaint'], true)) {
                $this->timeline->record('contact', $contactId, 'marketing.unsubscribed', 'Email opt-out reported by ' . $provider, null);
            }
        }

        return true;
    }
}
