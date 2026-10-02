<?php

declare(strict_types=1);

namespace Modules\Notification;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class NotificationLogRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'notification_logs';
    }

    public function record(int $recipientUserId, string $channel, string $status, ?string $detail, ?string $referenceType, ?int $referenceId): int
    {
        return $this->tenantInsertGetId([
            'recipient_user_id' => $recipientUserId,
            'channel' => $channel,
            'status' => $status,
            'detail' => $detail,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }

    public function recent(int $limit = 100): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM notification_logs WHERE 1 = 1 ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        );
    }
}
