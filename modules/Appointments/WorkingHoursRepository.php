<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** appointment_working_hours — weekly hours per resource (several ranges per day allowed). */
final class WorkingHoursRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'appointment_working_hours';
    }

    public function forResource(int $resourceId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM appointment_working_hours WHERE resource_id = :resource_id ORDER BY weekday, start_time',
            ['resource_id' => $resourceId]
        );
    }

    /** Every resource's hours in one query, for the calendar views. */
    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM appointment_working_hours WHERE 1 = 1 ORDER BY resource_id, weekday, start_time');
    }

    /** @param array<int, array{weekday: int, start_time: string, end_time: string}> $ranges */
    public function replaceForResource(int $resourceId, array $ranges): void
    {
        $this->transaction(function () use ($resourceId, $ranges) {
            $this->tenantDelete('resource_id = :resource_id', ['resource_id' => $resourceId]);

            foreach ($ranges as $range) {
                $this->tenantInsertGetId([
                    'resource_id' => $resourceId,
                    'weekday' => $range['weekday'],
                    'start_time' => $range['start_time'],
                    'end_time' => $range['end_time'],
                ]);
            }
        });
    }
}
