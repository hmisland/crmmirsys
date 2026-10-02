<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class NoteRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'notes';
    }

    public function forSubject(string $notableType, int $notableId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM notes WHERE notable_type = :type AND notable_id = :id ORDER BY created_at DESC',
            ['type' => $notableType, 'id' => $notableId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM notes WHERE id = :id', ['id' => $id]);
    }

    public function create(string $notableType, int $notableId, ?int $userId, string $body): int
    {
        return $this->tenantInsertGetId([
            'notable_type' => $notableType,
            'notable_id' => $notableId,
            'user_id' => $userId,
            'body' => $body,
        ]);
    }

    public function deleteNote(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
