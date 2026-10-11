<?php

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** client_record_sessions — individual visits/sessions of a client record. */
final class SessionRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'client_record_sessions';
    }

    public function forRecord(int $recordId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM client_record_sessions WHERE client_record_id = :record_id ORDER BY session_at DESC, id DESC',
            ['record_id' => $recordId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM client_record_sessions WHERE id = :id', ['id' => $id]);
    }

    /** The next session number within a service course (1-based). */
    public function nextNumberForService(int $serviceRecordId): int
    {
        $row = $this->tenantFindOne(
            'SELECT MAX(session_number) AS n FROM client_record_sessions WHERE service_record_id = :service_id',
            ['service_id' => $serviceRecordId]
        );

        return (int) ($row['n'] ?? 0) + 1;
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateSession(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteSession(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
