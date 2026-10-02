<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use Modules\Notification\NotificationRecipientRepository;

final class NotificationRecipientService
{
    public function __construct(private readonly NotificationRecipientRepository $recipients)
    {
    }

    public function forUser(int $userId): ?array
    {
        return $this->recipients->find($userId);
    }

    public function save(int $userId, ?string $baleChatId, ?string $telegramChatId): void
    {
        $baleChatId = $baleChatId !== null && trim($baleChatId) !== '' ? trim($baleChatId) : null;
        $telegramChatId = $telegramChatId !== null && trim($telegramChatId) !== '' ? trim($telegramChatId) : null;

        $this->recipients->upsert($userId, $baleChatId, $telegramChatId);
    }
}
