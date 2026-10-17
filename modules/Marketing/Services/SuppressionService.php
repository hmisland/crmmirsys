<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\SuppressionRepository;

/**
 * Opt-outs / suppression list: manual entries by users, unsubscribe
 * links, provider events (bounce, complaint, unsubscribe) and SMS
 * "blocked" delivery reports all end up here.
 */
final class SuppressionService
{
    public const CHANNELS = ['sms', 'email'];
    public const REASONS = ['unsubscribed', 'bounced', 'complaint', 'blocked', 'manual'];

    public function __construct(
        private readonly SuppressionRepository $suppressions,
        private readonly MessageRepository $messages,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
    ) {
    }

    public function page(array $user, array $filters, int $limit, int $offset): array
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->suppressions->page($filters, $limit, $offset);
    }

    public function count(array $user, array $filters): int
    {
        $this->authorize($user, MarketingPermission::VIEW);

        return $this->suppressions->count($filters);
    }

    public function addManual(array $user, string $channel, string $rawAddress): void
    {
        $this->authorize($user, MarketingPermission::MANAGE_SUPPRESSIONS);
        if (!in_array($channel, self::channels(), true)) {
            throw new ValidationException(['channel' => ['marketing.error.invalid_channel']]);
        }
        $address = AudienceResolver::normalize($channel, $rawAddress);
        if ($address === null) {
            throw new ValidationException(['address' => ['marketing.error.test_address']]);
        }

        $this->suppressions->add($channel, $address, 'manual', 'user', null, (int) $user['id']);
        $this->timeline->record('organization', OrganizationContext::id(), 'marketing.suppression_added', 'Suppression added (' . $channel . ')', (int) $user['id']);
    }

    /** Built-in channels plus installed phone-text channels (e.g. "bale" — its own opt-out list, separate from SMS). */
    public static function channels(): array
    {
        return array_values(array_unique(array_merge(self::CHANNELS, \Modules\Marketing\Channels\ChannelRegistry::extraChannelKeys())));
    }

    public function remove(array $user, int $id): void
    {
        $this->authorize($user, MarketingPermission::MANAGE_SUPPRESSIONS);
        $this->suppressions->remove($id);
        $this->timeline->record('organization', OrganizationContext::id(), 'marketing.suppression_removed', 'Suppression removed', (int) $user['id']);
    }

    /** Whether a campaign message exists in the CURRENT organization (unsubscribe link check). */
    public function messageExists(int $messageId): bool
    {
        return $this->messages->findById($messageId) !== null;
    }

    /**
     * Unsubscribe from a signed link — the caller has verified the token
     * and pinned OrganizationContext to the token's organization.
     *
     * @return bool whether the message existed
     */
    public function unsubscribeMessage(int $messageId): bool
    {
        $message = $this->messages->findById($messageId);
        if ($message === null) {
            return false;
        }

        $this->suppressions->add((string) $message['channel'], (string) $message['address'], 'unsubscribed', 'link', $message['contact_id'] !== null ? (int) $message['contact_id'] : null, null, true);
        if ($message['contact_id'] !== null) {
            $this->timeline->record('contact', (int) $message['contact_id'], 'marketing.unsubscribed', 'Unsubscribed from marketing ' . $message['channel'], null);
        }

        return true;
    }

    private function authorize(array $user, string $permission): void
    {
        if (!$this->authorization->can($user, $permission)) {
            throw new AuthorizationException();
        }
    }
}
