<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\Permission;
use Modules\ClientRecords\ServiceRecordRepository;
use Modules\ClientRecords\SessionRepository;

use function App\Infrastructure\Theme\t;

/**
 * Service history and sessions of a client record. Both are gated by
 * client_records.manage_sessions on the parent record (reading them
 * only needs client_records.view, except the sensitive `observations`
 * text). Links to products, invoices and follow-ups always go through
 * ClientIntegrations, i.e. through the owning module's own service.
 */
final class ClientTreatmentService
{
    public const SERVICE_STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];
    public const SESSION_STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    public function __construct(
        private readonly ServiceRecordRepository $services,
        private readonly SessionRepository $sessions,
        private readonly ClientRecordService $records,
        private readonly ClientIntegrations $integrations,
        private readonly TimelineService $timeline,
    ) {
    }

    // ---- Reading ---------------------------------------------------

    public function servicesFor(array $record): array
    {
        return $this->services->forRecord((int) $record['id']);
    }

    /** Sessions, with `observations` blanked for callers without view_sensitive. */
    public function sessionsFor(array $actingUser, array $record): array
    {
        $rows = $this->sessions->forRecord((int) $record['id']);

        if (!$this->records->canViewSensitive($actingUser, $record)) {
            foreach ($rows as &$row) {
                $row['observations'] = null;
            }
            unset($row);
        }

        return $rows;
    }

    public function findService(array $record, int $serviceId): array
    {
        $service = $this->services->findById($serviceId);

        if ($service === null || (int) $service['client_record_id'] !== (int) $record['id']) {
            throw new NotFoundException();
        }

        return $service;
    }

    public function findSession(array $actingUser, array $record, int $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);

        if ($session === null || (int) $session['client_record_id'] !== (int) $record['id']) {
            throw new NotFoundException();
        }

        if (!$this->records->canViewSensitive($actingUser, $record)) {
            $session['observations'] = null;
        }

        return $session;
    }

    public function nextSessionNumber(array $record, ?int $serviceId): ?int
    {
        if ($serviceId === null) {
            return null;
        }

        $this->findService($record, $serviceId);

        return $this->sessions->nextNumberForService($serviceId);
    }

    // ---- Services --------------------------------------------------

    public function createService(array $actingUser, array $record, array $input): array
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);

        $fields = $this->serviceFields($actingUser, $record, $input);
        $fields['client_record_id'] = (int) $record['id'];
        $fields['created_by_user_id'] = (int) $actingUser['id'];

        $id = $this->services->create($fields);
        $this->timeline->record('client_record', (int) $record['id'], 'service_added', 'Service added: ' . $fields['title'], (int) $actingUser['id']);

        return $this->services->findById($id);
    }

    public function updateService(array $actingUser, array $record, int $serviceId, array $input): array
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);
        $this->findService($record, $serviceId);

        $fields = $this->serviceFields($actingUser, $record, $input);
        $this->services->updateService($serviceId, $fields);
        $this->timeline->record('client_record', (int) $record['id'], 'service_updated', 'Service updated: ' . $fields['title'], (int) $actingUser['id']);

        return $this->services->findById($serviceId);
    }

    public function deleteService(array $actingUser, array $record, int $serviceId): void
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);
        $service = $this->findService($record, $serviceId);

        // Sessions survive as standalone visits (FK ON DELETE SET NULL) —
        // deleting a mistyped service must never erase visit history.
        $this->services->deleteService($serviceId);
        $this->timeline->record('client_record', (int) $record['id'], 'service_deleted', 'Service deleted: ' . $service['title'], (int) $actingUser['id']);
    }

    // ---- Sessions --------------------------------------------------

    public function createSession(array $actingUser, array $record, array $input): array
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);

        $fields = $this->sessionFields($actingUser, $record, $input, true);
        $fields['client_record_id'] = (int) $record['id'];
        $fields['created_by_user_id'] = (int) $actingUser['id'];

        if ($fields['service_record_id'] !== null && $fields['session_number'] === null) {
            $fields['session_number'] = $this->sessions->nextNumberForService($fields['service_record_id']);
        }

        $id = $this->sessions->create($fields);
        $this->markServiceInProgress($fields);
        $this->linkFollowUp($actingUser, $record, $id, $input);

        $this->timeline->record(
            'client_record',
            (int) $record['id'],
            $fields['status'] === 'scheduled' ? 'session_scheduled' : 'session_recorded',
            ($fields['status'] === 'scheduled' ? 'Session scheduled: ' : 'Session recorded: ') . $this->sessionLabel($record, $fields),
            (int) $actingUser['id']
        );

        return $this->sessions->findById($id);
    }

    public function updateSession(array $actingUser, array $record, int $sessionId, array $input): array
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);
        $existing = $this->findSession($actingUser, $record, $sessionId);

        $fields = $this->sessionFields($actingUser, $record, $input, $this->records->canViewSensitive($actingUser, $record));
        $this->sessions->updateSession($sessionId, $fields);
        $this->markServiceInProgress($fields);

        if ($existing['follow_up_id'] === null) {
            $this->linkFollowUp($actingUser, $record, $sessionId, $input);
        }

        $this->timeline->record('client_record', (int) $record['id'], 'session_updated', 'Session updated: ' . $this->sessionLabel($record, $fields), (int) $actingUser['id']);

        return $this->sessions->findById($sessionId);
    }

    /** Quick status change from the Appointments tab (completed / cancelled / no-show). */
    public function changeSessionStatus(array $actingUser, array $record, int $sessionId, string $status): void
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);
        $session = $this->findSession($actingUser, $record, $sessionId);

        if (!in_array($status, self::SESSION_STATUSES, true)) {
            throw new ValidationException(['status' => ['client_records.validation.invalid_status']]);
        }

        $this->sessions->updateSession($sessionId, ['status' => $status]);
        $this->timeline->record('client_record', (int) $record['id'], 'session_' . $status, 'Session ' . str_replace('_', '-', $status) . ': ' . $this->sessionLabel($record, $session), (int) $actingUser['id']);
    }

    public function deleteSession(array $actingUser, array $record, int $sessionId): void
    {
        $this->records->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_MANAGE_SESSIONS, $record);
        $session = $this->findSession($actingUser, $record, $sessionId);

        $this->sessions->deleteSession($sessionId);
        $this->timeline->record('client_record', (int) $record['id'], 'session_deleted', 'Session deleted: ' . $this->sessionLabel($record, $session), (int) $actingUser['id']);
    }

    // ---- Internals -------------------------------------------------

    private function serviceFields(array $actingUser, array $record, array $input): array
    {
        $errors = [];
        $productId = $this->intOrNull($input['product_id'] ?? null);
        $title = trim((string) ($input['title'] ?? ''));

        if ($productId !== null) {
            $product = $this->integrations->findProduct($actingUser, $productId);
            if ($product === null) {
                $errors['product_id'][] = 'client_records.validation.invalid_product';
            } elseif ($title === '') {
                $title = (string) $product['name'];
            }
        }

        if ($title === '') {
            $errors['title'][] = 'client_records.validation.service_title_required';
        }

        $status = (string) ($input['status'] ?? 'planned');
        if (!in_array($status, self::SERVICE_STATUSES, true)) {
            $errors['status'][] = 'client_records.validation.invalid_status';
        }

        $planned = $this->intOrNull($input['planned_sessions'] ?? null);
        if ($planned !== null && ($planned < 1 || $planned > 500)) {
            $errors['planned_sessions'][] = 'client_records.validation.planned_sessions_range';
        }

        $startedOn = $this->dateOrNull($input['started_on'] ?? null, 'started_on', $errors);
        $completedOn = $this->dateOrNull($input['completed_on'] ?? null, 'completed_on', $errors);

        $invoiceId = $this->validatedInvoiceId($actingUser, $record, $input['invoice_id'] ?? null, $errors);
        $providerId = $this->validatedProvider($actingUser, $input['provider_user_id'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'product_id' => $productId,
            'title' => mb_substr($title, 0, 255),
            'provider_user_id' => $providerId,
            'location' => $this->textOrNull($input['location'] ?? null, 150),
            'status' => $status,
            'planned_sessions' => $planned,
            'started_on' => $startedOn,
            'completed_on' => $completedOn,
            'invoice_id' => $invoiceId,
            'notes' => $this->textOrNull($input['notes'] ?? null),
        ];
    }

    private function sessionFields(array $actingUser, array $record, array $input, bool $includeSensitive): array
    {
        $errors = [];

        $serviceId = $this->intOrNull($input['service_record_id'] ?? null);
        if ($serviceId !== null) {
            try {
                $this->findService($record, $serviceId);
            } catch (NotFoundException) {
                $errors['service_record_id'][] = 'client_records.validation.invalid_service';
            }
        }

        $title = $this->textOrNull($input['title'] ?? null, 255);
        if ($serviceId === null && $title === null) {
            $errors['title'][] = 'client_records.validation.session_title_required';
        }

        $date = $this->dateOrNull($input['session_date'] ?? null, 'session_date', $errors);
        if ($date === null && !isset($errors['session_date'])) {
            $errors['session_date'][] = 'client_records.validation.date_required';
        }

        $time = trim((string) ($input['session_time'] ?? ''));
        if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['session_time'][] = 'client_records.validation.invalid_time';
        }

        $status = (string) ($input['status'] ?? 'completed');
        if (!in_array($status, self::SESSION_STATUSES, true)) {
            $errors['status'][] = 'client_records.validation.invalid_status';
        }

        $number = $this->intOrNull($input['session_number'] ?? null);
        if ($number !== null && ($number < 1 || $number > 999)) {
            $errors['session_number'][] = 'client_records.validation.session_number_range';
        }

        $nextOn = $this->dateOrNull($input['next_recommended_on'] ?? null, 'next_recommended_on', $errors);
        $this->dateOrNull($input['follow_up_on'] ?? null, 'follow_up_on', $errors);

        $invoiceId = $this->validatedInvoiceId($actingUser, $record, $input['invoice_id'] ?? null, $errors);
        $providerId = $this->validatedProvider($actingUser, $input['provider_user_id'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $fields = [
            'service_record_id' => $serviceId,
            'title' => $title,
            'session_number' => $number,
            'session_at' => $date . ' ' . ($time !== '' ? $time : '00:00') . ':00',
            'status' => $status,
            'provider_user_id' => $providerId,
            'notes' => $this->textOrNull($input['notes'] ?? null),
            'client_feedback' => $this->textOrNull($input['client_feedback'] ?? null),
            'next_recommended_on' => $nextOn,
            'invoice_id' => $invoiceId,
        ];

        if ($includeSensitive) {
            $fields['observations'] = $this->textOrNull($input['observations'] ?? null);
        }

        return $fields;
    }

    /**
     * "Follow up on <date>" on a session creates a real follow-up in the
     * Follow-ups module (related to the client's contact) and remembers
     * its id on the session. Skipped silently when Follow-ups isn't
     * available to this user — the session itself is still saved.
     */
    private function linkFollowUp(array $actingUser, array $record, int $sessionId, array $input): void
    {
        $date = trim((string) ($input['follow_up_on'] ?? ''));

        if ($date === '' || !$this->integrations->canCreateFollowUp($actingUser)) {
            return;
        }

        $title = trim((string) ($input['follow_up_title'] ?? ''));
        $session = $this->sessions->findById($sessionId);

        try {
            $followUp = $this->integrations->createFollowUp($actingUser, (int) $record['contact_id'], [
                // A user-visible title, so it's written in the creating user's language.
                'title' => $title !== '' ? mb_substr($title, 0, 255) : t('client_records.followup_default_title', ['label' => $this->sessionLabel($record, $session ?? [])]),
                'type' => 'call',
                'priority' => 'normal',
                'due_at' => $date . ' 09:00:00',
                'assignee_user_id' => $session['provider_user_id'] ?? '',
            ]);
        } catch (HttpException) {
            return;
        }

        if ($followUp !== null) {
            $this->sessions->updateSession($sessionId, ['follow_up_id' => (int) $followUp['id']]);
        }
    }

    /** The first real (non-scheduled) session of a planned course starts it, dated by that session. */
    private function markServiceInProgress(array $sessionFields): void
    {
        $serviceId = $sessionFields['service_record_id'];

        if ($serviceId === null || $sessionFields['status'] !== 'completed') {
            return;
        }

        $service = $this->services->findById($serviceId);
        if ($service !== null && $service['status'] === 'planned') {
            $this->services->updateService($serviceId, [
                'status' => 'in_progress',
                'started_on' => $service['started_on'] ?? substr((string) $sessionFields['session_at'], 0, 10),
            ]);
        }
    }

    private function sessionLabel(array $record, array $session): string
    {
        $label = (string) ($session['title'] ?? '');

        if (!empty($session['service_record_id'])) {
            $service = $this->services->findById((int) $session['service_record_id']);
            $label = $service !== null ? (string) $service['title'] : $label;
        }

        if (!empty($session['session_number'])) {
            $label .= ' #' . $session['session_number'];
        }

        return trim($label) !== '' ? trim($label) : (string) $record['record_number'];
    }

    private function validatedInvoiceId(array $actingUser, array $record, mixed $raw, array &$errors): ?int
    {
        $invoiceId = $this->intOrNull($raw);

        if ($invoiceId === null) {
            return null;
        }

        if ($this->integrations->invoiceForContact($actingUser, $invoiceId, (int) $record['contact_id']) === null) {
            $errors['invoice_id'][] = 'client_records.validation.invalid_invoice';

            return null;
        }

        return $invoiceId;
    }

    private function validatedProvider(array $actingUser, mixed $raw, array &$errors): ?int
    {
        try {
            return $this->records->validatedUserId($actingUser, $raw, 'provider_user_id');
        } catch (ValidationException) {
            $errors['provider_user_id'][] = 'client_records.validation.invalid_user';

            return null;
        }
    }

    private function dateOrNull(mixed $raw, string $field, array &$errors): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[$field][] = 'client_records.validation.invalid_date';

            return null;
        }

        return $value;
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
