<?php

declare(strict_types=1);

namespace Modules\InternalMail;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class ContactBroadcastRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_broadcasts';
    }

    public function recent(int $limit = 20): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contact_broadcasts WHERE 1 = 1 ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
    }

    public function create(int $sentByUserId, string $subject, string $body, int $recipientCount, int $failedCount): int
    {
        return $this->tenantInsertGetId([
            'sent_by_user_id' => $sentByUserId,
            'subject' => $subject,
            'body' => $body,
            'recipient_count' => $recipientCount,
            'failed_count' => $failedCount,
        ]);
    }
}
