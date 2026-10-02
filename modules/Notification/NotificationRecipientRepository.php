<?php

declare(strict_types=1);

namespace Modules\Notification;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * `phone_number` is a legacy column, kept in the schema but no longer
 * read or written here — a user's phone number now lives on
 * `users.mobile_phone` (Core), the single source of truth, set from
 * their Profile page. This table now only owns the two
 * notification-specific channel IDs: Bale and Telegram.
 */
final class NotificationRecipientRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'notification_recipients';
    }

    public function find(int $userId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM notification_recipients WHERE user_id = :user_id', ['user_id' => $userId]);
    }

    public function upsert(int $userId, ?string $baleChatId, ?string $telegramChatId): void
    {
        $existing = $this->find($userId);

        if ($existing === null) {
            $this->tenantInsertGetId([
                'user_id' => $userId,
                'bale_chat_id' => $baleChatId,
                'telegram_chat_id' => $telegramChatId,
            ]);

            return;
        }

        $this->tenantUpdate(
            ['bale_chat_id' => $baleChatId, 'telegram_chat_id' => $telegramChatId],
            'user_id = :user_id',
            ['user_id' => $userId]
        );
    }
}
