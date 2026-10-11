<?php

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** client_record_files — metadata over Core attachments (media + documents). */
final class ClientFileRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'client_record_files';
    }

    public function forRecord(int $recordId, ?string $kind = null): array
    {
        $sql = 'SELECT * FROM client_record_files WHERE client_record_id = :record_id';
        $params = ['record_id' => $recordId];

        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }

        return $this->tenantFindMany($sql . ' ORDER BY COALESCE(taken_at, DATE(created_at)) DESC, id DESC', $params);
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM client_record_files WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function deleteFile(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
