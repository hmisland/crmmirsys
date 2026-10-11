<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** appointment_types — what is booked, with its default length and buffer. */
final class AppointmentTypeRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'appointment_types';
    }

    public function all(bool $activeOnly = false): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM appointment_types WHERE ' . ($activeOnly ? 'is_active = 1' : '1 = 1') . ' ORDER BY name, id'
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM appointment_types WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateType(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteType(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
