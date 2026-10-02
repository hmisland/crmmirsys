<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class TimelineEventRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'timeline_events';
    }

    public function forSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM timeline_events WHERE subject_type = :type AND subject_id = :id
             ORDER BY created_at DESC LIMIT ' . max(1, min(200, $limit)),
            ['type' => $subjectType, 'id' => $subjectId]
        );
    }

    public function record(string $subjectType, int $subjectId, string $type, string $description, ?int $actorUserId): int
    {
        return $this->tenantInsertGetId([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'type' => $type,
            'description' => $description,
            'actor_user_id' => $actorUserId,
        ]);
    }
}
