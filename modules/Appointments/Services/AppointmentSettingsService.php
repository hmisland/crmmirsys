<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Appointments\AppointmentRepository;
use Modules\Appointments\AppointmentTypeRepository;
use Modules\Appointments\ResourceRepository;
use Modules\Appointments\WorkingHoursRepository;

/**
 * Resources (with their weekly working hours) and appointment types.
 * Reading active ones is open to anyone who may view appointments
 * (the booking form needs them); changing them needs
 * appointments.manage_settings.
 */
final class AppointmentSettingsService
{
    public const RESOURCE_KINDS = ['person', 'place', 'equipment'];
    /** Working ranges per weekday offered by the form (covers split shifts). */
    public const RANGES_PER_DAY = 2;

    public function __construct(
        private readonly ResourceRepository $resources,
        private readonly AppointmentTypeRepository $types,
        private readonly WorkingHoursRepository $hours,
        private readonly AppointmentRepository $appointments,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function canManage(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, Permission::APPOINTMENTS_MANAGE_SETTINGS);
    }

    public function resources(bool $activeOnly = false): array
    {
        return $this->resources->all($activeOnly);
    }

    public function types(bool $activeOnly = false): array
    {
        return $this->types->all($activeOnly);
    }

    // ---- Resources -------------------------------------------------

    public function findResource(int $id): array
    {
        $resource = $this->resources->findById($id);

        if ($resource === null) {
            throw new NotFoundException();
        }

        return $resource;
    }

    /** @return array<int, list<array{start: string, end: string}>> weekday => ranges, padded for the form */
    public function hoursForm(?int $resourceId): array
    {
        $byDay = array_fill(0, 7, []);

        if ($resourceId !== null) {
            foreach ($this->hours->forResource($resourceId) as $row) {
                $byDay[(int) $row['weekday']][] = [
                    'start' => substr((string) $row['start_time'], 0, 5),
                    'end' => substr((string) $row['end_time'], 0, 5),
                ];
            }
        }

        foreach ($byDay as $day => $ranges) {
            $byDay[$day] = array_pad($ranges, self::RANGES_PER_DAY, ['start' => '', 'end' => '']);
        }

        return $byDay;
    }

    public function createResource(array $actingUser, array $input): array
    {
        $this->authorizeManage($actingUser);
        [$fields, $ranges] = $this->resourceFields($actingUser, $input);

        $id = $this->resources->create($fields);
        $this->hours->replaceForResource($id, $ranges);

        return $this->findResource($id);
    }

    public function updateResource(array $actingUser, int $id, array $input): array
    {
        $this->authorizeManage($actingUser);
        $this->findResource($id);
        [$fields, $ranges] = $this->resourceFields($actingUser, $input);

        $this->resources->updateResource($id, $fields);
        $this->hours->replaceForResource($id, $ranges);

        return $this->findResource($id);
    }

    public function deleteResource(array $actingUser, int $id): void
    {
        $this->authorizeManage($actingUser);
        $this->findResource($id);

        if ($this->appointments->countUsing('resource_id', $id) > 0) {
            throw new ValidationException(['resource' => ['appointments.validation.resource_in_use']]);
        }

        $this->resources->deleteResource($id); // working hours cascade
    }

    // ---- Types -----------------------------------------------------

    public function findType(int $id): array
    {
        $type = $this->types->findById($id);

        if ($type === null) {
            throw new NotFoundException();
        }

        return $type;
    }

    public function createType(array $actingUser, array $input): array
    {
        $this->authorizeManage($actingUser);

        return $this->findType($this->types->create($this->typeFields($input)));
    }

    public function updateType(array $actingUser, int $id, array $input): array
    {
        $this->authorizeManage($actingUser);
        $this->findType($id);
        $this->types->updateType($id, $this->typeFields($input));

        return $this->findType($id);
    }

    public function deleteType(array $actingUser, int $id): void
    {
        $this->authorizeManage($actingUser);
        $this->findType($id);

        if ($this->appointments->countUsing('appointment_type_id', $id) > 0) {
            throw new ValidationException(['type' => ['appointments.validation.type_in_use']]);
        }

        $this->types->deleteType($id);
    }

    // ---- Internals -------------------------------------------------

    /** @return array{0: array, 1: list<array{weekday: int, start_time: string, end_time: string}>} */
    private function resourceFields(array $actingUser, array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'appointments.validation.name_required';
        }

        $kind = (string) ($input['kind'] ?? 'person');
        if (!in_array($kind, self::RESOURCE_KINDS, true)) {
            $errors['kind'][] = 'appointments.validation.invalid_kind';
        }

        $userId = null;
        $rawUser = $input['user_id'] ?? '';
        if ($rawUser !== '' && $rawUser !== null) {
            $user = is_numeric($rawUser) ? $this->users->findById((int) $rawUser) : null;
            if ($user === null || (int) ($user['organization_id'] ?? 0) !== (int) $actingUser['organization_id']) {
                $errors['user_id'][] = 'appointments.validation.invalid_user';
            } else {
                $userId = (int) $user['id'];
            }
        }

        $capacity = (string) ($input['capacity'] ?? '1');
        if (!ctype_digit($capacity) || (int) $capacity < 1 || (int) $capacity > 500) {
            $errors['capacity'][] = 'appointments.validation.capacity_range';
        }

        $sort = trim((string) ($input['sort_order'] ?? '0'));
        if ($sort !== '' && !preg_match('/^-?\d{1,4}$/', $sort)) {
            $errors['sort_order'][] = 'appointments.validation.invalid_number';
        }

        $ranges = $this->hourRanges($input['hours'] ?? [], $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $description = trim((string) ($input['description'] ?? ''));

        return [[
            'name' => mb_substr($name, 0, 150),
            'kind' => $kind,
            'user_id' => $kind === 'person' ? $userId : null,
            'capacity' => (int) $capacity,
            'description' => $description !== '' ? $description : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'sort_order' => (int) $sort,
        ], $ranges];
    }

    /**
     * hours[weekday][n][start|end] → validated rows. A range with both
     * ends empty is skipped; a half-filled, reversed or overlapping one
     * is an error on hours.{weekday}.
     */
    private function hourRanges(mixed $raw, array &$errors): array
    {
        $rows = [];
        $raw = is_array($raw) ? $raw : [];

        for ($day = 0; $day <= 6; $day++) {
            $dayRanges = [];

            foreach (array_slice(is_array($raw[$day] ?? null) ? $raw[$day] : [], 0, self::RANGES_PER_DAY) as $range) {
                $start = trim((string) ($range['start'] ?? ''));
                $end = trim((string) ($range['end'] ?? ''));

                if ($start === '' && $end === '') {
                    continue;
                }

                $valid = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end);
                if (!$valid || $start >= $end) {
                    $errors['hours.' . $day][] = 'appointments.validation.invalid_hours';
                    continue 2;
                }

                $dayRanges[] = [$start, $end];
            }

            usort($dayRanges, fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($dayRanges); $i++) {
                if ($dayRanges[$i][0] < $dayRanges[$i - 1][1]) {
                    $errors['hours.' . $day][] = 'appointments.validation.overlapping_hours';
                    continue 2;
                }
            }

            foreach ($dayRanges as [$start, $end]) {
                $rows[] = ['weekday' => $day, 'start_time' => $start . ':00', 'end_time' => $end . ':00'];
            }
        }

        return $rows;
    }

    private function typeFields(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'][] = 'appointments.validation.name_required';
        }

        $duration = (string) ($input['duration_minutes'] ?? '');
        if (!ctype_digit($duration) || (int) $duration < 5 || (int) $duration > 1440) {
            $errors['duration_minutes'][] = 'appointments.validation.duration_range';
        }

        $buffer = (string) ($input['buffer_minutes'] ?? '0');
        $buffer = $buffer === '' ? '0' : $buffer;
        if (!ctype_digit($buffer) || (int) $buffer > 480) {
            $errors['buffer_minutes'][] = 'appointments.validation.buffer_range';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $description = trim((string) ($input['description'] ?? ''));

        return [
            'name' => mb_substr($name, 0, 150),
            'duration_minutes' => (int) $duration,
            'buffer_minutes' => (int) $buffer,
            'description' => $description !== '' ? $description : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ];
    }

    private function authorizeManage(array $actingUser): void
    {
        if (!$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }
}
