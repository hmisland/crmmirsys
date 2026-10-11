<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Services;

use App\Application\Services\AttachmentService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\NoteService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use Modules\ClientRecords\ClientFileRepository;
use Modules\ClientRecords\ClientRecordRepository;

/**
 * The client record itself: a structured-history layer around an
 * existing Contact (never a copy of it). Every public method takes the
 * acting user and re-checks permission here, regardless of what the
 * route middleware already checked (defense in depth, Phase 0 §65).
 *
 * Record-level scope: OWN matches the record's owner OR its assigned
 * specialist; TEAM/DEPARTMENT match the team/department captured from
 * the creator. All evaluated by AuthorizationEngine::can().
 *
 * Validation messages are translation keys; templates pass them to t().
 */
final class ClientRecordService
{
    public const STATUSES = ['active', 'inactive', 'archived'];
    public const SENSITIVE_FIELDS = ['sensitivities', 'restrictions', 'observations'];
    public const CUSTOM_FIELD_ENTITY = 'client_record';

    public function __construct(
        private readonly ClientRecordRepository $records,
        private readonly ContactRepository $contacts,
        private readonly UserRepository $users,
        private readonly ClientFileRepository $files,
        private readonly AttachmentService $attachments,
        private readonly NoteService $notes,
        private readonly CustomFieldService $customFields,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    public function list(array $actingUser, array $filters = []): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::CLIENT_RECORDS_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $allowed = array_intersect_key($filters, array_flip(['q', 'status', 'assigned_user_id', 'contact_id', 'limit']));

        return $this->records->search($allowed + $this->scopeFilters($actingUser, $scope));
    }

    public function find(array $actingUser, int $id): array
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_VIEW, $record);

        return $record;
    }

    /** For the contact page's "open client record" link — null when there is none or the caller can't see it. */
    public function findForContact(array $actingUser, int $contactId): ?array
    {
        $record = $this->records->findByContact($contactId);

        if ($record === null || !$this->can($actingUser, Permission::CLIENT_RECORDS_VIEW, $record)) {
            return null;
        }

        return $record;
    }

    public function contactFor(array $record): array
    {
        $contact = $this->contacts->findById((int) $record['contact_id']);

        if ($contact === null) {
            throw new NotFoundException();
        }

        return $contact;
    }

    /**
     * The record as the caller may see it: sensitive fields are removed
     * server-side (not just hidden in the template) when the caller
     * lacks view_sensitive, and every sensitive view is written to the
     * audit log channel.
     */
    public function presentable(array $actingUser, array $record): array
    {
        if ($this->canViewSensitive($actingUser, $record)) {
            $this->logger->info('audit.client_record.sensitive_viewed', [
                'organization_id' => $record['organization_id'],
                'client_record_id' => $record['id'],
                'user_id' => $actingUser['id'],
            ]);

            return $record;
        }

        foreach (self::SENSITIVE_FIELDS as $field) {
            $record[$field] = null;
        }

        return $record;
    }

    public function customFieldDefinitions(): array
    {
        return $this->customFields->definitionsFor(self::CUSTOM_FIELD_ENTITY);
    }

    public function customFieldValues(int $recordId): array
    {
        return $this->customFields->valuesFor(self::CUSTOM_FIELD_ENTITY, $recordId);
    }

    public function notesFor(int $recordId): array
    {
        return $this->notes->forSubject('client_record', $recordId);
    }

    /**
     * Org members (id => display name) for the specialist picker and
     * for resolving names in lists, without a users join per query.
     *
     * @return array<int, string>
     */
    public function organizationUserNames(array $actingUser): array
    {
        $names = [];
        foreach ($this->users->findAllInOrganization((int) $actingUser['organization_id']) as $member) {
            $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
            $names[(int) $member['id']] = $name !== '' ? $name : (string) $member['email'];
        }

        return $names;
    }

    // ---------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::CLIENT_RECORDS_CREATE)) {
            throw new AuthorizationException();
        }

        $contactId = (int) ($input['contact_id'] ?? 0);
        $contact = $contactId > 0 ? $this->contacts->findById($contactId) : null; // tenant-scoped

        if ($contact === null) {
            throw new ValidationException(['contact_id' => ['client_records.validation.contact_required']]);
        }

        if ($this->records->findByContact($contactId) !== null) {
            throw new ValidationException(['contact_id' => ['client_records.validation.contact_has_record']]);
        }

        $fields = $this->intakeFields($actingUser, $input, true);
        $fields['contact_id'] = $contactId;
        $fields['status'] = 'active';
        $fields['owner_id'] = (int) $actingUser['id'];
        $fields['team_id'] = $actingUser['team_id'] ?? null;
        $fields['department_id'] = $actingUser['department_id'] ?? null;

        $id = $this->insertWithRecordNumber($fields);
        $this->customFields->saveValues(self::CUSTOM_FIELD_ENTITY, $id, $this->customInput($input));

        $actor = (int) $actingUser['id'];
        $this->timeline->record('client_record', $id, 'client_record_created', 'Client record opened', $actor);
        $this->timeline->record('contact', $contactId, 'client_record_created', 'Client record opened', $actor);

        return $this->requireRecord($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_EDIT, $record);

        $fields = $this->intakeFields($actingUser, $input, $this->canViewSensitive($actingUser, $record));

        if (isset($input['status']) && in_array($input['status'], self::STATUSES, true)) {
            $fields += $this->statusFields((string) $input['status'], $record);
        }

        $this->records->updateRecord($id, $fields);
        $this->customFields->saveValues(self::CUSTOM_FIELD_ENTITY, $id, $this->customInput($input));
        $this->timeline->record('client_record', $id, 'client_record_updated', 'Client record updated', (int) $actingUser['id']);

        return $this->requireRecord($id);
    }

    /** Archive / restore / deactivate — the module's soft-delete. */
    public function changeStatus(array $actingUser, int $id, string $status): array
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_EDIT, $record);

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => ['client_records.validation.invalid_status']]);
        }

        $this->records->updateRecord($id, $this->statusFields($status, $record));
        $this->timeline->record('client_record', $id, 'client_record_status_' . $status, 'Client record status: ' . $status, (int) $actingUser['id']);

        return $this->requireRecord($id);
    }

    /**
     * Permanent delete. Removes the record's files through the Core
     * AttachmentService first (so nothing is left on disk), then the
     * row — sessions/services/file metadata go with it via FK cascade.
     * The contact keeps a timeline entry saying a record was deleted.
     */
    public function delete(array $actingUser, int $id): void
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_DELETE, $record);

        foreach ($this->files->forRecord($id) as $file) {
            $this->attachments->delete((int) $file['attachment_id']);
        }

        $this->records->deleteRecord($id);
        $this->timeline->record('contact', (int) $record['contact_id'], 'client_record_deleted', 'Client record ' . $record['record_number'] . ' deleted', (int) $actingUser['id']);
    }

    /**
     * Deletes every id the acting user may delete; the rest are skipped
     * (not theirs or already gone) — each one goes through delete() and
     * its record-level permission check.
     *
     * @param array<int|string, mixed> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $actingUser, array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)), 0, 500) as $id) {
            try {
                $this->delete($actingUser, $id);
                $deleted++;
            } catch (AuthorizationException | NotFoundException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Per-row delete flag for the list page — the same record-level
     * check delete() enforces.
     *
     * @return array<int, bool>
     */
    public function deletableIds(array $actingUser, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $this->can($actingUser, Permission::CLIENT_RECORDS_DELETE, $row);
        }

        return $result;
    }

    public function addNote(array $actingUser, int $id, string $body): void
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_EDIT, $record);

        try {
            $this->notes->add('client_record', $id, (int) $actingUser['id'], $body);
        } catch (ValidationException) {
            throw new ValidationException(['body' => ['client_records.validation.note_required']]);
        }
    }

    public function deleteNote(array $actingUser, int $id, int $noteId): void
    {
        $record = $this->requireRecord($id);
        $this->authorizeRecord($actingUser, Permission::CLIENT_RECORDS_EDIT, $record);

        // The note must belong to THIS record — never trust the id alone.
        $belongs = array_filter($this->notes->forSubject('client_record', $id), fn ($n) => (int) $n['id'] === $noteId);
        if ($belongs === []) {
            throw new NotFoundException();
        }

        $this->notes->delete($noteId);
        $this->timeline->record('client_record', $id, 'note_deleted', 'Deleted a note', (int) $actingUser['id']);
    }

    // ---------------------------------------------------------------
    // Authorization helpers (shared with the other module services)
    // ---------------------------------------------------------------

    public function can(array $actingUser, string $permission, array $record): bool
    {
        $asOwner = ['owner_id' => $record['owner_id'], 'team_id' => $record['team_id'], 'department_id' => $record['department_id']];

        if ($this->authorization->can($actingUser, $permission, $asOwner)) {
            return true;
        }

        return $record['assigned_user_id'] !== null
            && $this->authorization->can($actingUser, $permission, ['owner_id' => $record['assigned_user_id']] + $asOwner);
    }

    public function canViewSensitive(array $actingUser, array $record): bool
    {
        return $this->can($actingUser, Permission::CLIENT_RECORDS_VIEW_SENSITIVE, $record);
    }

    public function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    public function requireRecord(int $id): array
    {
        $record = $this->records->findById($id); // tenant-scoped: another org's id is "not found"

        if ($record === null) {
            throw new NotFoundException();
        }

        return $record;
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

    /** Two simultaneous creates can compute the same next number; the unique key catches it and we take the next one. */
    private function insertWithRecordNumber(array $fields): int
    {
        for ($attempt = 1; ; $attempt++) {
            $fields['record_number'] = $this->records->nextRecordNumber();

            try {
                return $this->records->create($fields);
            } catch (\PDOException $e) {
                $isNumberCollision = ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'uniq_client_records_number');
                if (!$isNumberCollision || $attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    private function statusFields(string $status, array $record): array
    {
        return [
            'status' => $status,
            'archived_at' => $status === 'archived' ? ($record['archived_at'] ?? date('Y-m-d H:i:s')) : null,
        ];
    }

    private function intakeFields(array $actingUser, array $input, bool $includeSensitive): array
    {
        $text = static fn (string $key): ?string => isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;

        $fields = [
            'important_notes' => $text('important_notes'),
            'preferences' => $text('preferences'),
            'assigned_user_id' => $this->validatedUserId($actingUser, $input['assigned_user_id'] ?? null, 'assigned_user_id'),
        ];

        // A caller who can't see the sensitive fields can't overwrite
        // them either — they're left untouched rather than blanked.
        if ($includeSensitive) {
            foreach (self::SENSITIVE_FIELDS as $field) {
                $fields[$field] = $text($field);
            }
        }

        return $fields;
    }

    /**
     * custom[key] values as CustomFieldService expects them. A
     * multiselect posts a hidden empty entry (so "nothing ticked" still
     * submits the key) — drop those blanks before they're stored.
     */
    private function customInput(array $input): array
    {
        $custom = (array) ($input['custom'] ?? []);

        foreach ($custom as $key => $value) {
            if (is_array($value)) {
                $custom[$key] = array_values(array_filter(array_map('strval', $value), fn (string $v) => $v !== ''));
            }
        }

        return $custom;
    }

    /** A user id from input must belong to the caller's own organization. */
    public function validatedUserId(array $actingUser, mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $user = $this->users->findById((int) $raw);

        if ($user === null || (int) ($user['organization_id'] ?? 0) !== (int) $actingUser['organization_id']) {
            throw new ValidationException([$field => ['client_records.validation.invalid_user']]);
        }

        return (int) $user['id'];
    }
}
