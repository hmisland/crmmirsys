<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Appointments\AppointmentRepository;
use Modules\Appointments\AppointmentTypeRepository;
use Modules\Appointments\ResourceRepository;

/**
 * Booking, rescheduling and status changes. Every public method takes
 * the acting user and re-checks permission here, whatever the route
 * middleware already checked.
 *
 * Booking rules:
 *  - a resource never holds more overlapping appointments than its
 *    capacity (hard rule, checked under a row lock on the resource);
 *  - outside the resource's working hours, or a staff member who is
 *    already booked at that time, is a warning the user may override
 *    by confirming ("save anyway").
 *
 * Record scope: OWN matches the creator OR the assigned staff member;
 * TEAM/DEPARTMENT match what was captured from the creator.
 * Validation messages are translation keys; templates pass them to t().
 */
final class AppointmentService
{
    public const STATUSES = ['requested', 'scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
    /** Error key that tells the form the remaining problems may be overridden. */
    public const FORCE_KEY = '_force';

    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ResourceRepository $resources,
        private readonly AppointmentTypeRepository $types,
        private readonly AvailabilityService $availability,
        private readonly ContactRepository $contacts,
        private readonly UserRepository $users,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    // ---- Reading ---------------------------------------------------

    public function list(array $actingUser, array $filters = []): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::APPOINTMENTS_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $allowed = array_intersect_key($filters, array_flip([
            'from', 'to', 'resource_id', 'assigned_user_id', 'contact_id', 'appointment_type_id',
            'status', 'active_only', 'q', 'limit', 'newest_first',
        ]));

        return $this->appointments->search($allowed + $this->scopeFilters($actingUser, $scope));
    }

    public function find(array $actingUser, int $id): array
    {
        $appointment = $this->appointments->findById($id); // org-scoped: another org's id is "not found"

        if ($appointment === null) {
            throw new NotFoundException();
        }

        $this->authorize($actingUser, Permission::APPOINTMENTS_VIEW, $appointment);

        return $appointment;
    }

    public function can(array $actingUser, string $permission, ?array $appointment = null): bool
    {
        if ($appointment === null) {
            return $this->authorization->can($actingUser, $permission);
        }

        $asOwner = [
            'owner_id' => $appointment['owner_id'],
            'team_id' => $appointment['team_id'],
            'department_id' => $appointment['department_id'],
        ];

        if ($this->authorization->can($actingUser, $permission, $asOwner)) {
            return true;
        }

        return $appointment['assigned_user_id'] !== null
            && $this->authorization->can($actingUser, $permission, ['owner_id' => $appointment['assigned_user_id']] + $asOwner);
    }

    // ---- Writing ---------------------------------------------------

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::APPOINTMENTS_CREATE)) {
            throw new AuthorizationException();
        }

        $fields = $this->validatedFields($actingUser, $input);
        $fields['source'] = 'staff';
        $fields['owner_id'] = (int) $actingUser['id'];
        $fields['team_id'] = $actingUser['team_id'] ?? null;
        $fields['department_id'] = $actingUser['department_id'] ?? null;

        $id = $this->appointments->transaction(function () use ($fields, $input) {
            $this->checkAvailability($fields, null, $this->forced($input));

            return $this->appointments->create($fields);
        });

        $appointment = $this->appointments->findById($id);
        $this->record($appointment, 'appointment_booked', 'Appointment booked: ' . $this->label($appointment), $actingUser);

        return $appointment;
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $existing = $this->find($actingUser, $id);
        $this->authorize($actingUser, Permission::APPOINTMENTS_EDIT, $existing);

        $fields = $this->validatedFields($actingUser, $input);

        $this->appointments->transaction(function () use ($fields, $input, $id) {
            $this->checkAvailability($fields, $id, $this->forced($input));
            $this->appointments->updateAppointment($id, $fields);
        });

        $appointment = $this->appointments->findById($id);
        $moved = $existing['starts_at'] !== $appointment['starts_at'] || $existing['ends_at'] !== $appointment['ends_at']
            || $existing['resource_id'] !== $appointment['resource_id'];

        $this->record(
            $appointment,
            $moved ? 'appointment_rescheduled' : 'appointment_updated',
            ($moved ? 'Appointment rescheduled: ' : 'Appointment updated: ') . $this->label($appointment),
            $actingUser
        );

        return $appointment;
    }

    public function changeStatus(array $actingUser, int $id, string $status, string $reason = ''): array
    {
        $appointment = $this->find($actingUser, $id);
        $this->authorize($actingUser, Permission::APPOINTMENTS_EDIT, $appointment);

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => ['appointments.validation.invalid_status']]);
        }

        if ($status === $appointment['status']) {
            return $appointment;
        }

        $fields = ['status' => $status];
        if ($status === 'cancelled') {
            $reason = trim($reason);
            $fields['cancel_reason'] = $reason !== '' ? mb_substr($reason, 0, 255) : null;
        }

        $reopens = !in_array($appointment['status'], AppointmentRepository::BLOCKING_STATUSES, true)
            && in_array($status, AppointmentRepository::BLOCKING_STATUSES, true);

        $this->appointments->transaction(function () use ($appointment, $fields, $reopens, $id) {
            // Re-activating a cancelled / no-show appointment takes its
            // slot back, so capacity must still allow it.
            if ($reopens) {
                $this->checkCapacity($appointment, $id);
            }

            $this->appointments->updateAppointment($id, $fields);
        });

        $updated = $this->appointments->findById($id);
        $this->record($updated, 'appointment_' . $status, 'Appointment ' . str_replace('_', ' ', $status) . ': ' . $this->label($updated), $actingUser);

        return $updated;
    }

    public function delete(array $actingUser, int $id): void
    {
        $appointment = $this->find($actingUser, $id);
        $this->authorize($actingUser, Permission::APPOINTMENTS_DELETE, $appointment);

        $this->appointments->deleteAppointment($id);
        $this->record($appointment, 'appointment_deleted', 'Appointment deleted: ' . $this->label($appointment), $actingUser);
    }

    // ---- Form options ----------------------------------------------

    public function contactOptions(): array
    {
        return $this->contacts->search([]);
    }

    /** @return array<int, string> org members, id => display name */
    public function userNames(array $actingUser): array
    {
        $names = [];
        foreach ($this->users->findAllInOrganization((int) $actingUser['organization_id']) as $member) {
            $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            $names[(int) $member['id']] = $name !== '' ? $name : (string) $member['email'];
        }

        return $names;
    }

    // ---- Internals -------------------------------------------------

    private function validatedFields(array $actingUser, array $input): array
    {
        $errors = [];

        $contactId = $this->intOrNull($input['contact_id'] ?? null);
        // No contact = internal meeting; a given contact must be in this org.
        if ($contactId !== null && $this->contacts->findById($contactId) === null) { // tenant-scoped
            $errors['contact_id'][] = 'appointments.validation.invalid_contact';
        }

        $type = null;
        $typeId = $this->intOrNull($input['appointment_type_id'] ?? null);
        if ($typeId !== null) {
            $type = $this->types->findById($typeId);
            if ($type === null) {
                $errors['appointment_type_id'][] = 'appointments.validation.invalid_type';
            }
        }

        $resource = null;
        $resourceId = $this->intOrNull($input['resource_id'] ?? null);
        if ($resourceId !== null) {
            $resource = $this->resources->findById($resourceId);
            if ($resource === null) {
                $errors['resource_id'][] = 'appointments.validation.invalid_resource';
            }
        }

        $assignedId = $this->intOrNull($input['assigned_user_id'] ?? null);
        if ($assignedId !== null) {
            $user = $this->users->findById($assignedId);
            if ($user === null || (int) ($user['organization_id'] ?? 0) !== (int) $actingUser['organization_id']) {
                $errors['assigned_user_id'][] = 'appointments.validation.invalid_user';
                $assignedId = null;
            }
        } elseif ($resource !== null && $resource['user_id'] !== null) {
            // Booking a person resource assigns it to that person by default.
            $assignedId = (int) $resource['user_id'];
        }

        $title = $this->textOrNull($input['title'] ?? null, 255);
        if ($title === null && $typeId === null) {
            $errors['title'][] = 'appointments.validation.title_or_type_required';
        }

        $date = trim((string) ($input['date'] ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date === '' || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors['date'][] = 'appointments.validation.date_required';
        }

        $time = trim((string) ($input['start_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['start_time'][] = 'appointments.validation.invalid_time';
        }

        $durationRaw = trim((string) ($input['duration_minutes'] ?? ''));
        $duration = $durationRaw !== '' ? $this->intOrNull($durationRaw) : ($type !== null ? (int) $type['duration_minutes'] : null);
        if ($duration === null || $duration < 5 || $duration > 1440) {
            $errors['duration_minutes'][] = 'appointments.validation.duration_range';
        }

        $status = (string) ($input['status'] ?? 'scheduled');
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'][] = 'appointments.validation.invalid_status';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $startsAt = $date . ' ' . $time . ':00';
        $fields = [
            'contact_id' => $contactId,
            'appointment_type_id' => $typeId,
            'resource_id' => $resourceId,
            'assigned_user_id' => $assignedId,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + $duration * 60),
            'status' => $status,
            'notes' => $this->textOrNull($input['notes'] ?? null),
        ];

        // A cancellation reason only means something while cancelled.
        if ($status !== 'cancelled') {
            $fields['cancel_reason'] = null;
        }

        return $fields;
    }

    /**
     * Capacity is a hard rule; working hours and a busy staff member are
     * warnings unless $forced. Called inside a transaction: the resource
     * row lock serializes concurrent bookings of the same resource.
     */
    private function checkAvailability(array $fields, ?int $excludeId, bool $forced): void
    {
        if (!in_array($fields['status'], AppointmentRepository::BLOCKING_STATUSES, true)) {
            return; // a cancelled / no-show entry occupies nothing
        }

        $this->checkCapacity($fields, $excludeId);

        if ($forced) {
            return;
        }

        $warnings = [];
        $resourceId = $fields['resource_id'];

        if ($resourceId !== null && !$this->availability->isWithinHours((int) $resourceId, $fields['starts_at'], $fields['ends_at'])) {
            $warnings['start_time'][] = 'appointments.validation.outside_hours';
        }

        if ($fields['assigned_user_id'] !== null
            && $this->appointments->countOverlappingForUser((int) $fields['assigned_user_id'], $fields['starts_at'], $fields['ends_at'], $excludeId) > 0) {
            $warnings['assigned_user_id'][] = 'appointments.validation.user_busy';
        }

        if ($warnings !== []) {
            $warnings[self::FORCE_KEY] = ['appointments.validation.force_hint'];
            throw new ValidationException($warnings);
        }
    }

    private function checkCapacity(array $fields, ?int $excludeId): void
    {
        if ($fields['resource_id'] === null) {
            return;
        }

        $resourceId = (int) $fields['resource_id'];
        $this->resources->lockForBooking($resourceId);
        $resource = $this->resources->findById($resourceId);

        $buffer = 0;
        if ($fields['appointment_type_id'] !== null) {
            $type = $this->types->findById((int) $fields['appointment_type_id']);
            $buffer = $type !== null ? (int) $type['buffer_minutes'] : 0;
        }

        $end = date('Y-m-d H:i:s', strtotime((string) $fields['ends_at']) + $buffer * 60);
        $taken = $this->appointments->countOverlappingOnResource($resourceId, (string) $fields['starts_at'], $end, $excludeId);

        if ($resource !== null && $taken >= max(1, (int) $resource['capacity'])) {
            throw new ValidationException(['start_time' => ['appointments.validation.resource_full']]);
        }
    }

    private function forced(array $input): bool
    {
        return ($input['force'] ?? '') === '1';
    }

    private function authorize(array $actingUser, string $permission, array $appointment): void
    {
        if (!$this->can($actingUser, $permission, $appointment)) {
            throw new AuthorizationException();
        }
    }

    /** Timeline entries go on the contact, so they show on the contact page. */
    private function record(array $appointment, string $type, string $description, array $actingUser): void
    {
        if (empty($appointment['contact_id'])) {
            return; // internal meeting: no contact timeline
        }
        $this->timeline->record('contact', (int) $appointment['contact_id'], $type, $description, (int) $actingUser['id']);
    }

    private function label(array $appointment): string
    {
        $what = (string) ($appointment['title'] ?? '') !== '' ? (string) $appointment['title'] : (string) ($appointment['type_name'] ?? '');

        return trim($what . ' — ' . substr((string) $appointment['starts_at'], 0, 16), ' —');
    }

    /** @return array<string, int> */
    private function scopeFilters(array $actingUser, Scope $scope): array
    {
        return match ($scope) {
            Scope::OWN => ['visible_to_user_id' => (int) $actingUser['id']],
            Scope::TEAM => ['team_id' => (int) ($actingUser['team_id'] ?? 0)],
            Scope::DEPARTMENT => ['department_id' => (int) ($actingUser['department_id'] ?? 0)],
            default => [],
        };
    }

    private function intOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function textOrNull(mixed $raw, ?int $max = null): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        return $max !== null ? mb_substr($value, 0, $max) : $value;
    }
}
