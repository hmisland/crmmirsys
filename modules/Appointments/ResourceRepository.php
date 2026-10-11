<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** appointment_resources — people, places and equipment that can be booked. */
final class ResourceRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'appointment_resources';
    }

    public function all(bool $activeOnly = false): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM appointment_resources WHERE ' . ($activeOnly ? 'is_active = 1' : '1 = 1') . ' ORDER BY sort_order, name, id'
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM appointment_resources WHERE id = :id', ['id' => $id]);
    }

    /**
     * Row lock for the duration of the surrounding transaction, so two
     * bookings on the same resource are checked for overlap one after
     * the other, never both against the same "free" state.
     */
    public function lockForBooking(int $id): void
    {
        $this->tenantFindOne('SELECT id FROM appointment_resources WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateResource(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteResource(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
