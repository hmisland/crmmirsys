<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Core\Container;
use App\Infrastructure\Integrations\Events\ExternalOrderStatusChanged;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Messaging\DeliveryReceipt;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Messaging\TransactionalMessage;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationRepository;
use Modules\CustomerSatisfaction\CustomerSatisfactionModule;
use Modules\CustomerSatisfaction\CxRepository;

/**
 * Survey invitations — Customer Satisfaction decides WHY, WHO, WHICH
 * survey, WHICH link and WHEN; delivery is Marketing's job through
 * Core's TransactionalDeliveryInterface (resolved lazily from the
 * container, so this module never depends on Marketing's classes).
 *
 *  1. fromOrderEvent(): an "order completed" event (any source) creates
 *     one scheduled invitation per active survey with that trigger.
 *     Idempotent per (survey, order). Orders completed long ago (seen
 *     for the first time during an initial import) are ignored.
 *  2. dispatchDue() (cron task "cx.invitations"): for each due
 *     invitation, pick a channel the organization can use AND the
 *     customer has an address for; issue a fresh single-use public link;
 *     hand ONE message to the deliverer. No channel available → the
 *     invitation waits ("waiting_channel") and is retried hourly for up
 *     to 7 days. At most once: an invitation is claimed ("sending")
 *     before delivery and never re-sent if a run dies mid-way.
 */
final class InvitationService
{
    public const MAX_EVENT_AGE_DAYS = 7;
    public const MAX_ATTEMPTS = 5;
    public const LINK_PURPOSE = 'cx_survey';
    public const LINK_SUBJECT = 'cx_invitation';

    public function __construct(
        private readonly CxRepository $repository,
        private readonly PublicLinkService $links,
        private readonly OrganizationEntitlements $entitlements,
        private readonly Container $container,
        private readonly string $langPath,
    ) {
    }

    /** @return int invitations created */
    public function fromOrderEvent(ExternalOrderStatusChanged $event): int
    {
        if (!$event->isCompleted() || !$this->entitlements->organizationHas($event->organizationId, CustomerSatisfactionModule::KEY)) {
            return 0;
        }
        if (strtotime($event->occurredAt . ' UTC') < time() - self::MAX_EVENT_AGE_DAYS * 86400) {
            return 0;
        }
        if ($event->customerEmail === null && $event->customerPhone === null) {
            return 0;
        }

        return OrganizationContext::runAs($event->organizationId, function () use ($event): int {
            $created = 0;
            foreach ($this->repository->triggeredSurveys('order.completed') as $survey) {
                $created += $this->repository->createInvitation([
                    'survey_id' => (int) $survey['id'],
                    'source' => $event->source,
                    'context_type' => 'order',
                    'context_ref' => mb_substr($event->orderRef, 0, 120),
                    'context_label' => mb_substr($event->orderNumber, 0, 60),
                    'contact_id' => $event->contactId,
                    'recipient_name' => $event->customerName !== '' ? mb_substr($event->customerName, 0, 200) : null,
                    'recipient_email' => $event->customerEmail,
                    'recipient_phone' => $event->customerPhone,
                    'status' => 'scheduled',
                    'send_after' => date('Y-m-d H:i:s', time() + 60 * (int) $survey['send_delay_minutes']),
                ]) ? 1 : 0;
            }

            return $created;
        });
    }

    /** @return array{sent: int, waiting: int, failed: int, expired: int} */
    public function dispatchDue(int $budgetSeconds, int $limit = 50): array
    {
        $deadline = time() + max(1, $budgetSeconds);
        $this->entitlements->reset();
        $this->repository->recoverStaleSending();
        $stats = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'expired' => $this->repository->expire()];
        $delivery = $this->container->has(TransactionalDeliveryInterface::class)
            ? $this->container->make(TransactionalDeliveryInterface::class)
            : null;

        foreach ($this->repository->dueInvitations($limit) as $invitation) {
            if (time() >= $deadline) {
                break;
            }
            $organizationId = (int) $invitation['organization_id'];
            if (!$this->entitlements->organizationHas($organizationId, CustomerSatisfactionModule::KEY)) {
                $this->repository->updateInvitation((int) $invitation['id'], $organizationId, ['status' => 'cancelled', 'last_error' => 'cx.error.module_unavailable']);
                continue;
            }
            $result = OrganizationContext::runAs($organizationId, fn () => $this->dispatchOne($invitation, $delivery));
            $stats[$result]++;
        }

        return $stats;
    }

    /** @return string sent | waiting | failed */
    private function dispatchOne(array $invitation, ?TransactionalDeliveryInterface $delivery): string
    {
        $id = (int) $invitation['id'];
        $organizationId = (int) $invitation['organization_id'];
        $survey = $this->repository->survey((int) $invitation['survey_id']);
        if ($survey === null || $survey['status'] !== 'active') {
            $this->repository->updateInvitation($id, $organizationId, ['status' => 'cancelled', 'last_error' => 'cx.error.survey_inactive']);

            return 'failed';
        }

        $channel = $this->chooseChannel($invitation, $survey, $delivery?->availableChannels($organizationId) ?? []);
        if ($delivery === null || $channel === null) {
            $this->repository->updateInvitation($id, $organizationId, [
                'status' => 'waiting_channel',
                'last_error' => 'cx.error.no_channel',
                'send_after' => date('Y-m-d H:i:s', time() + 3600),
            ]);

            return 'waiting';
        }
        if (!$this->repository->claimInvitation($id, $organizationId)) {
            return 'failed'; // another run took it
        }

        $locale = $this->locale($invitation, $organizationId);
        $link = $this->links->issue(
            self::LINK_PURPOSE,
            self::LINK_SUBJECT,
            $id,
            new \DateTimeImmutable('+' . (int) $survey['link_valid_days'] . ' days', new \DateTimeZone('UTC')),
            $locale,
            1,
        );
        $translator = new Translator($locale, $this->langPath);
        $name = (string) ($invitation['recipient_name'] ?? '');
        $replace = ['name' => $name !== '' ? $name : $translator->get('cx.invitation.customer'), 'order' => (string) ($invitation['context_label'] ?? ''), 'link' => $link['url']];
        $text = (string) ($survey['message_text'] ?? '');
        $text = $text !== ''
            ? strtr($text, [':name' => $replace['name'], ':order' => $replace['order'], ':link' => $replace['link']])
            : $translator->get('cx.invitation.default_text', $replace);
        if (!str_contains($text, $link['url'])) {
            $text .= "\n" . $link['url'];
        }

        try {
            $receipt = $delivery->deliver(new TransactionalMessage(
                $organizationId,
                $channel,
                $channel === 'email' ? (string) $invitation['recipient_email'] : (string) $invitation['recipient_phone'],
                $name,
                $translator->get('cx.invitation.subject'),
                $text,
                self::LINK_PURPOSE,
                'cx_invitation:' . $id,
                $invitation['contact_id'] !== null ? (int) $invitation['contact_id'] : null,
                $locale,
            ));
        } catch (\Throwable) {
            $receipt = DeliveryReceipt::retry('cx.error.delivery_exception');
        }

        if ($receipt->status === DeliveryReceipt::ACCEPTED) {
            $this->repository->updateInvitation($id, $organizationId, [
                'status' => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
                'channel' => $channel,
                'locale' => $locale,
                'public_link_id' => $link['id'],
                'delivery_ref' => $receipt->providerReference !== null ? mb_substr($receipt->providerReference, 0, 100) : null,
                'last_error' => null,
            ]);

            return 'sent';
        }

        // Not delivered: the link that was never sent must not stay usable.
        $this->links->revoke($link['id']);
        $attempts = (int) $invitation['attempts'] + 1;
        $retry = $receipt->status === DeliveryReceipt::RETRY && $attempts < self::MAX_ATTEMPTS;
        $this->repository->updateInvitation($id, $organizationId, [
            'status' => $retry ? 'scheduled' : 'failed',
            'channel' => $channel,
            'last_error' => mb_substr((string) $receipt->errorKey, 0, 100),
            'send_after' => date('Y-m-d H:i:s', time() + 900 * $attempts),
        ]);

        return $retry ? 'waiting' : 'failed';
    }

    /** The survey's preferred channel when usable, otherwise the deliverer's first usable one. */
    private function chooseChannel(array $invitation, array $survey, array $available): ?string
    {
        $hasAddress = fn (string $channel): bool => $channel === 'email'
            ? !empty($invitation['recipient_email'])
            : !empty($invitation['recipient_phone']);

        $preferred = $survey['channel'] ?? null;
        if (is_string($preferred) && in_array($preferred, $available, true) && $hasAddress($preferred)) {
            return $preferred;
        }
        foreach ($available as $channel) {
            if (in_array($channel, SurveyService::CHANNELS, true) && $hasAddress($channel)) {
                return $channel;
            }
        }

        return null;
    }

    private function locale(array $invitation, int $organizationId): string
    {
        $locale = $invitation['locale'] ?? null;
        if (!is_string($locale) || !in_array($locale, Translator::SUPPORTED_LOCALES, true)) {
            $locale = (string) ((new OrganizationRepository())->findById($organizationId)['default_language'] ?? 'fa');
        }

        return in_array($locale, Translator::SUPPORTED_LOCALES, true) ? $locale : 'en';
    }
}
