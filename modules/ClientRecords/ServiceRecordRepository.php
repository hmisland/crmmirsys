<?php

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** client_record_services — the service history of one client record. */
final class ServiceRecordRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'client_record_services';
    }

    public function forRecord(int $recordId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM client_record_services WHERE client_record_id = :record_id
             ORDER BY started_on IS NULL, started_on DESC, id DESC',
            ['record_id' => $recordId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM client_record_services WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateService(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteService(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
