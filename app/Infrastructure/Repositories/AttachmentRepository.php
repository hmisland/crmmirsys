<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class AttachmentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'attachments';
    }

    public function forSubject(string $attachableType, int $attachableId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM attachments WHERE attachable_type = :type AND attachable_id = :id ORDER BY created_at DESC',
            ['type' => $attachableType, 'id' => $attachableId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM attachments WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function deleteAttachment(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
