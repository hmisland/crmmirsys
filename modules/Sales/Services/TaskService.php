<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Sales\TaskRepository;

use function App\Infrastructure\Theme\t;

/**
 * Comments and attachments deliberately reuse Phase 5's shared
 * NoteService/AttachmentService (with subject type 'task') rather than
 * inventing a separate "comment" concept — a comment IS a note, and a
 * task's attachments work exactly like a contact's or company's.
 *
 * Access (Task Manager Phase 0). One rule decides whether a user may
 * see or act on a task, and lists, buttons and routes all use it:
 *  - the permission's scope: ALL/ORGANIZATION = every task of the
 *    organization; OWN = tasks the user created or is assigned to;
 *    TEAM/DEPARTMENT = OWN plus tasks whose creator or any assignee is
 *    in the user's team/department (tasks have no team column, so their
 *    team is their people's — as a project's team is its manager's);
 *  - a task related to a project also needs access to that project;
 *  - acting on a task always needs tasks.view on it as well.
 * Archived tasks are read-only except for archive/unarchive/delete.
 * Soft-deleted tasks never load (TaskRepository).
 */
final class TaskService
{
    private const ALLOWED_RELATED_TYPES = ['contact', 'company', 'lead', 'deal'];

    /** Workflow order (also the order shown in the UI). Archiving is a separate flag, not a status. */
    public const STATUSES = ['pending', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled'];

    /** Allowed status changes: from => to. Open tasks move freely; a finished one can only be reopened. */
    public const TRANSITIONS = [
        'pending' => ['in_progress', 'on_hold', 'completed', 'rejected', 'cancelled'],
        'in_progress' => ['pending', 'on_hold', 'completed', 'rejected', 'cancelled'],
        'on_hold' => ['pending', 'in_progress', 'completed', 'rejected', 'cancelled'],
        'completed' => ['pending', 'in_progress'],
        'rejected' => ['pending', 'in_progress'],
        'cancelled' => ['pending', 'in_progress'],
    ];

    /** The closed statuses: leaving one of them is a reopen and needs tasks.reopen. */
    public const REOPEN_FROM = ['completed', 'rejected', 'cancelled'];

    private const MAX_TITLE_LENGTH = 255;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly NoteService $notes,
        private readonly AttachmentService $attachments,
        private readonly AuthorizationEngine $authorization,
        private readonly UserRepository $users,
        private readonly RecordTypeRegistry $recordTypes,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly LeadRepository $leads,
    ) {
    }

    /** A record's tasks (e.g. a deal's) that this user may see; [] without tasks.view. */
    public function visibleForSubject(array $actingUser, string $relatedType, int $relatedId): array
    {
        return $this->visibleRows($actingUser, $this->tasks->forSubject($relatedType, $relatedId));
    }

    /** The dashboard's upcoming tasks of this user, same visibility rule as the lists. */
    public function upcomingForUser(array $actingUser): array
    {
        return $this->visibleRows($actingUser, $this->tasks->upcomingForUser((int) $actingUser['id']));
    }

    /** "My tasks": all statuses (finished ones stay visible), or the archive. */
    public function forUser(array $actingUser, bool $archived, ?string $status = null): array
    {
        $this->authorize($actingUser, Permission::TASKS_VIEW);

        return $this->visibleRows(
            $actingUser,
            $this->tasks->forUser((int) $actingUser['id'], $archived, in_array($status, self::STATUSES, true) ? $status : null)
        );
    }

    /** The task list's views: assigned to me (default), created by me, all tasks, my archive. */
    public const LIST_VIEWS = ['mine', 'created', 'all', 'archived'];

    /**
     * One view of the task list. "all" is only for users whose tasks.view
     * covers the whole organization; every view keeps the visibility rule
     * (visibleRows), so nothing outside the user's scope is listed.
     */
    public function listFor(array $actingUser, string $view, ?string $status = null): array
    {
        $this->authorize($actingUser, Permission::TASKS_VIEW);
        $status = in_array($status, self::STATUSES, true) ? $status : null;

        return match ($view) {
            'created' => $this->visibleRows($actingUser, $this->tasks->listing((int) $actingUser['id'], $status)),
            'all' => $this->canListAll($actingUser)
                ? $this->visibleRows($actingUser, $this->tasks->listing(null, $status))
                : throw new AuthorizationException(),
            'archived' => $this->forUser($actingUser, true, $status),
            default => $this->forUser($actingUser, false, $status),
        };
    }

    /** May this user list every task of the organization (tasks.view at organization scope)? */
    public function canListAll(array $actingUser): bool
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::TASKS_VIEW);

        return $scope === Scope::ALL || $scope === Scope::ORGANIZATION;
    }

    /**
     * Blanket (list-level) capabilities, for controls that are not tied
     * to one task (the create form). Per-task controls use actionsFor().
     *
     * @return array{create: bool, change_status: bool, archive: bool, delete: bool, edit: bool, reopen: bool}
     */
    public function capabilities(array $actingUser): array
    {
        return [
            'create' => $this->authorization->can($actingUser, Permission::TASKS_CREATE),
            'change_status' => $this->authorization->can($actingUser, Permission::TASKS_CHANGE_STATUS),
            'archive' => $this->authorization->can($actingUser, Permission::TASKS_ARCHIVE),
            'delete' => $this->authorization->can($actingUser, Permission::TASKS_DELETE),
            'edit' => $this->authorization->can($actingUser, Permission::TASKS_EDIT),
            'reopen' => $this->authorization->can($actingUser, Permission::TASKS_REOPEN),
        ];
    }

    /**
     * What this user may do with each task, decided by the same rule the
     * actions themselves enforce — so the UI never offers a control that
     * would be refused.
     *
     * @return array<int, array{statuses: string[], toggle: ?string, edit: bool, archive: bool, delete: bool, comment: bool, upload: bool}>
     */
    public function actionsFor(array $actingUser, array $tasks): array
    {
        $permissions = [Permission::TASKS_VIEW, Permission::TASKS_EDIT, Permission::TASKS_CHANGE_STATUS,
            Permission::TASKS_REOPEN, Permission::TASKS_ARCHIVE, Permission::TASKS_DELETE];
        $scopes = [];
        foreach ($permissions as $permission) {
            $scopes[$permission] = $this->authorization->scopeFor($actingUser, $permission);
        }
        [$assignees, $teams] = $this->context($tasks, $scopes);

        $result = [];
        foreach ($tasks as $task) {
            $id = (int) $task['id'];
            $may = fn (string $permission): bool => $this->scopeAllows($scopes[$permission], $actingUser, $task, $assignees[$id] ?? [], $teams)
                && $this->scopeAllows($scopes[Permission::TASKS_VIEW], $actingUser, $task, $assignees[$id] ?? [], $teams)
                && $this->relatedAccessible($actingUser, $task);
            $active = ($task['archived_at'] ?? null) === null;

            $statuses = [];
            if ($active && $may(Permission::TASKS_CHANGE_STATUS)) {
                $reopenOk = !in_array($task['status'], self::REOPEN_FROM, true) || $may(Permission::TASKS_REOPEN);
                $statuses = $reopenOk ? (self::TRANSITIONS[$task['status']] ?? []) : [];
            }
            $toggleTo = $task['status'] === 'completed' ? 'pending' : 'completed';

            $result[$id] = [
                'statuses' => $statuses,
                'toggle' => in_array($toggleTo, $statuses, true) ? $toggleTo : null,
                'edit' => $active && $may(Permission::TASKS_EDIT),
                'archive' => $may(Permission::TASKS_ARCHIVE),
                'delete' => $may(Permission::TASKS_DELETE),
                'comment' => $active && $may(Permission::TASKS_VIEW),
                'upload' => $active && $may(Permission::TASKS_EDIT),
            ];
        }

        return $result;
    }

    public function find(array $actingUser, int $id): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_VIEW, $task);

        return $task;
    }

    public function assigneeIds(int $taskId): array
    {
        return $this->tasks->assigneeIds($taskId);
    }

    /**
     * Users a task form may offer: the organization's active users, plus
     * any inactive user already on the task (kept, never silently dropped).
     *
     * @param int[] $currentIds
     */
    public function assignableUsers(int $organizationId, array $currentIds = []): array
    {
        return array_values(array_filter(
            $this->users->findAllInOrganization($organizationId),
            fn (array $u) => ($u['status'] ?? '') === 'active' || in_array((int) $u['id'], $currentIds, true)
        ));
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::TASKS_CREATE);

        // Everything is validated before the first write.
        $title = $this->validatedTitle($input);
        $dueAt = $this->validatedDueAt($input['due_at'] ?? null);
        [$relatedType, $relatedId] = $this->validatedRelated($actingUser, $input);
        $assigneeIds = $this->validatedAssigneeIds($input, (int) $actingUser['id']);

        $fields = [
            'title' => $title,
            'description' => $this->orNull($input, 'description'),
            'due_at' => $dueAt,
            'priority' => in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true) ? $input['priority'] : 'medium',
            'assigned_to_user_id' => $assigneeIds[0],
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'created_by_user_id' => (int) $actingUser['id'],
        ];

        // The task and its assignee rows are written together or not at all.
        $id = $this->tasks->atomically(function () use ($fields, $assigneeIds): int {
            $id = $this->tasks->create($fields);
            $this->tasks->setAssignees($id, $assigneeIds);

            return $id;
        });

        return $this->tasks->findById($id);
    }

    /**
     * Fields absent from $input stay as they are; a submitted empty
     * description or due date clears it. The related record is fixed at
     * creation and never changed here.
     */
    public function update(array $actingUser, int $id, array $input): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_EDIT, $task);
        $this->requireNotArchived($task);

        $fields = ['title' => $this->validatedTitle($input)];
        if (array_key_exists('description', $input)) {
            $fields['description'] = $this->orNull($input, 'description');
        }
        if (array_key_exists('due_at', $input)) {
            $fields['due_at'] = $this->validatedDueAt($input['due_at']);
        }
        if (in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true)) {
            $fields['priority'] = $input['priority'];
        }

        $assigneeIds = null;
        if (isset($input['assigned_to_user_ids'])) {
            $current = $this->tasks->assigneeIds($id);
            if (!empty($task['assigned_to_user_id'])) {
                $current[] = (int) $task['assigned_to_user_id'];
            }
            $assigneeIds = $this->validatedAssigneeIds($input, (int) $actingUser['id'], $current);
            $fields['assigned_to_user_id'] = $assigneeIds[0];
        }

        $this->tasks->atomically(function () use ($id, $fields, $assigneeIds) {
            $this->tasks->updateTask($id, $fields);
            if ($assigneeIds !== null) {
                $this->tasks->setAssignees($id, $assigneeIds);
            }
        });

        return $this->tasks->findById($id);
    }

    /** The list checkbox: done ↔ pending. Unchecking a completed task is a reopen. */
    public function toggleComplete(array $actingUser, int $id): array
    {
        $task = $this->requireTask($id);

        return $this->changeStatus($actingUser, $id, $task['status'] === 'completed' ? 'pending' : 'completed');
    }

    public function changeStatus(array $actingUser, int $id, string $status): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_CHANGE_STATUS, $task);
        $this->requireNotArchived($task);

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => [t('tasks.invalid_status')]]);
        }
        if (!in_array($status, self::TRANSITIONS[$task['status']] ?? [], true)) {
            throw new ValidationException(['status' => [t('tasks.error.invalid_transition')]]);
        }
        if (in_array($task['status'], self::REOPEN_FROM, true)) {
            $this->authorizeRecord($actingUser, Permission::TASKS_REOPEN, $task);
        }

        $this->tasks->updateTask($id, [
            'status' => $status,
            // completed_at is "when it was finished"; it only stays set while the task is completed.
            'completed_at' => $status === 'completed' ? ($task['completed_at'] ?? date('Y-m-d H:i:s')) : null,
        ]);

        return $this->tasks->findById($id);
    }

    /** Archive hides a task from the working lists without changing its status; restorable. */
    public function archive(array $actingUser, int $id): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_ARCHIVE, $task);

        if ($task['archived_at'] === null) {
            $this->tasks->updateTask($id, ['archived_at' => date('Y-m-d H:i:s'), 'archived_by_user_id' => (int) $actingUser['id']]);
        }

        return $this->tasks->findById($id);
    }

    public function unarchive(array $actingUser, int $id): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_ARCHIVE, $task);

        $this->tasks->updateTask($id, ['archived_at' => null, 'archived_by_user_id' => null]);

        return $this->tasks->findById($id);
    }

    /** Soft delete: the task disappears from every list and route; its data stays. */
    public function delete(array $actingUser, int $id): void
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_DELETE, $task);

        $this->tasks->softDelete($id, (int) $actingUser['id']);
    }

    // --- Comments (Notes reused, subject type 'task') -------------------

    public function comments(int $taskId): array
    {
        return $this->notes->forSubject('task', $taskId);
    }

    public function addComment(array $actingUser, int $taskId, string $body): array
    {
        $task = $this->find($actingUser, $taskId);
        $this->requireNotArchived($task);

        return $this->notes->add('task', $taskId, (int) $actingUser['id'], $body);
    }

    /**
     * The task must be viewable and not archived, the note must be THIS
     * task's (404 otherwise), and the user must be its author or hold
     * tasks.delete on the task.
     */
    public function deleteComment(array $actingUser, int $taskId, int $noteId): void
    {
        $task = $this->find($actingUser, $taskId);
        $this->requireNotArchived($task);

        $note = null;
        foreach ($this->comments($taskId) as $row) {
            if ((int) $row['id'] === $noteId) {
                $note = $row;
                break;
            }
        }
        if ($note === null) {
            throw new NotFoundException();
        }
        if (!$this->mayDeleteItem($actingUser, $task, $note['user_id'] ?? null)) {
            throw new AuthorizationException();
        }

        $this->notes->deleteFromSubject('task', $taskId, $noteId);
    }

    // --- Attachments (reused, subject type 'task') -----------------------

    public function attachments(int $taskId): array
    {
        return $this->attachments->forSubject('task', $taskId);
    }

    public function addAttachment(array $actingUser, int $taskId, array $file): array
    {
        $task = $this->requireTask($taskId);
        $this->authorizeRecord($actingUser, Permission::TASKS_EDIT, $task);
        $this->requireNotArchived($task);

        return $this->attachments->upload('task', $taskId, (int) $actingUser['organization_id'], (int) $actingUser['id'], $file);
    }

    /**
     * Same rule as comments: viewable, not archived, THIS task's file
     * (404 otherwise), and uploaded by the user or tasks.delete on the
     * task. AttachmentService removes the row and the stored file.
     */
    public function deleteAttachment(array $actingUser, int $taskId, int $attachmentId): void
    {
        $task = $this->find($actingUser, $taskId);
        $this->requireNotArchived($task);

        $attachment = $this->attachments->findForSubject('task', $taskId, $attachmentId);
        if ($attachment === null) {
            throw new NotFoundException();
        }
        if (!$this->mayDeleteItem($actingUser, $task, $attachment['user_id'] ?? null)) {
            throw new AuthorizationException();
        }

        $this->attachments->deleteFromSubject('task', $taskId, $attachmentId);
    }

    /** For the task page: may this user delete a comment/attachment by $authorId? */
    public function mayDeleteItem(array $actingUser, array $task, mixed $authorId): bool
    {
        if (($task['archived_at'] ?? null) !== null || !$this->allows($actingUser, Permission::TASKS_VIEW, $task)) {
            return false;
        }

        if ($authorId !== null && (int) $authorId === (int) $actingUser['id']) {
            return true;
        }

        return $this->allows($actingUser, Permission::TASKS_DELETE, $task);
    }

    /**
     * The attachment for a download, only when the user may view the task
     * (find(): tasks.view + the task's scope rule — the task page's own
     * check) AND the file really belongs to this task. Authorization
     * failure → AuthorizationException; anything else → null (404).
     */
    public function attachmentForDownload(array $actingUser, int $taskId, int $attachmentId): ?array
    {
        $this->find($actingUser, $taskId);

        return $this->attachments->findForSubject('task', $taskId, $attachmentId);
    }

    public function attachmentPath(array $attachment): string
    {
        return $this->attachments->absolutePath($attachment);
    }

    // --- Validation --------------------------------------------------------

    private function validatedTitle(array $input): string
    {
        $title = trim((string) ($input['title'] ?? ''));

        if ($title === '') {
            throw new ValidationException(['title' => [t('tasks.error.title_required')]]);
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new ValidationException(['title' => [t('tasks.error.title_too_long')]]);
        }

        return $title;
    }

    /**
     * Empty = no due date. Otherwise a real Gregorian date, as the
     * datepicker's hidden field sends it (YYYY-MM-DD), optionally with a
     * time; stored as a full DATETIME.
     */
    private function validatedDueAt(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            foreach (['!Y-m-d' => 'Y-m-d', '!Y-m-d H:i' => 'Y-m-d H:i', '!Y-m-d H:i:s' => 'Y-m-d H:i:s'] as $format => $echo) {
                $date = \DateTimeImmutable::createFromFormat($format, $value);
                if ($date !== false && $date->format($echo) === $value) {
                    return $date->format('Y-m-d H:i:s');
                }
            }
        }

        throw new ValidationException(['due_at' => [t('tasks.error.invalid_due_date')]]);
    }

    /**
     * At most one related record. Its type must be supported and the
     * record must exist in this organization and be viewable by the user
     * (permission and record scope). Missing and forbidden look the same.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function validatedRelated(array $actingUser, array $input): array
    {
        $type = $input['related_type'] ?? null;

        if ($type === null || $type === '') {
            return [null, null];
        }

        $rawId = $input['related_id'] ?? null;
        $id = is_int($rawId) ? $rawId : (is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : 0);

        if (!is_string($type) || !in_array($type, self::ALLOWED_RELATED_TYPES, true) || $id <= 0
            || $this->relatedRecord($actingUser, $type, $id) === null) {
            throw new ValidationException(['related_type' => [t('tasks.error.invalid_related')]]);
        }

        return [$type, $id];
    }

    /** The related row when this user may view it, else null. */
    private function relatedRecord(array $actingUser, string $type, int $id): ?array
    {
        // Core types have no registry finder: load through their tenant-scoped
        // repositories and apply the view permission with the row's scope —
        // the same checks RecordTypeRegistry::find() makes for module types.
        [$repository, $permission] = match ($type) {
            'contact' => [$this->contacts, Permission::CONTACTS_VIEW],
            'company' => [$this->companies, Permission::COMPANIES_VIEW],
            'lead' => [$this->leads, Permission::LEADS_VIEW],
            default => [null, null],
        };

        if ($repository === null) {
            return $this->recordTypes->find($type, $id, $actingUser);
        }

        $row = $repository->findById($id);

        return $row !== null && $this->authorization->can($actingUser, $permission, $row) ? $row : null;
    }

    /**
     * Every id must be a user of this organization. A NEW assignee must
     * also be active; one already on the task ($keepIds) may stay even if
     * inactive since. Nothing submitted = the acting user. Any bad id
     * rejects the whole submission — nothing is dropped silently.
     *
     * @param int[] $keepIds
     * @return int[] at least one id
     */
    private function validatedAssigneeIds(array $input, int $fallbackUserId, array $keepIds = []): array
    {
        $raw = $input['assigned_to_user_ids'] ?? $input['assigned_to_user_id'] ?? null;
        $values = is_array($raw) ? $raw : ($raw === null ? [] : [$raw]);
        $values = array_values(array_filter($values, fn ($v) => $v !== '' && $v !== null));

        if ($values === []) {
            return [$fallbackUserId];
        }

        $ids = [];
        foreach ($values as $value) {
            $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
            $user = $id > 0 ? $this->users->findById($id) : null;
            $valid = $user !== null
                && (int) ($user['organization_id'] ?? 0) === OrganizationContext::id()
                && (($user['status'] ?? '') === 'active' || in_array($id, $keepIds, true));

            if (!$valid) {
                throw new ValidationException(['assigned_to_user_ids' => [t('tasks.error.invalid_assignee')]]);
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    // --- Access --------------------------------------------------------------

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    /** $permission on this task, plus tasks.view on it (acting on a task implies seeing it). */
    private function authorizeRecord(array $actingUser, string $permission, array $task): void
    {
        if (!$this->allows($actingUser, Permission::TASKS_VIEW, $task)
            || ($permission !== Permission::TASKS_VIEW && !$this->allows($actingUser, $permission, $task))) {
            throw new AuthorizationException();
        }
    }

    private function allows(array $actingUser, string $permission, array $task): bool
    {
        $scope = $this->authorization->scopeFor($actingUser, $permission);
        [$assignees, $teams] = $this->context([$task], [$scope]);

        return $this->scopeAllows($scope, $actingUser, $task, $assignees[(int) $task['id']] ?? [], $teams)
            && $this->relatedAccessible($actingUser, $task);
    }

    /** Rows this user may view (tasks.view scope + related-record rule); [] without tasks.view. */
    private function visibleRows(array $actingUser, array $rows): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::TASKS_VIEW);
        if ($scope === null) {
            return [];
        }
        [$assignees, $teams] = $this->context($rows, [$scope]);

        return array_values(array_filter($rows, fn (array $task) => $this->scopeAllows($scope, $actingUser, $task, $assignees[(int) $task['id']] ?? [], $teams)
            && $this->relatedAccessible($actingUser, $task)));
    }

    /**
     * The lookups scopeAllows() needs for these tasks, fetched once:
     * assignee ids (OWN and narrower), and the people's team/department
     * (TEAM/DEPARTMENT only).
     *
     * @param array<?Scope> $scopes
     * @return array{0: array<int, int[]>, 1: array<int, array{team_id: ?int, department_id: ?int}>}
     */
    private function context(array $tasks, array $scopes): array
    {
        $narrow = array_filter($scopes, fn (?Scope $s) => $s !== null && $s !== Scope::ALL && $s !== Scope::ORGANIZATION);
        if ($narrow === [] || $tasks === []) {
            return [[], []];
        }

        $assignees = $this->tasks->assigneeIdsFor(array_map(fn (array $t) => (int) $t['id'], $tasks));

        $teams = [];
        if (in_array(Scope::TEAM, $narrow, true) || in_array(Scope::DEPARTMENT, $narrow, true)) {
            $people = [];
            foreach ($tasks as $task) {
                $people = array_merge($people, $this->people($task, $assignees[(int) $task['id']] ?? []));
            }
            $teams = $this->tasks->teamsOf($people);
        }

        return [$assignees, $teams];
    }

    /** @return int[] creator, primary assignee and every assignee */
    private function people(array $task, array $assigneeIds): array
    {
        $ids = array_merge([(int) ($task['created_by_user_id'] ?? 0), (int) ($task['assigned_to_user_id'] ?? 0)], $assigneeIds);

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /** The permission scope applied to one task. A null scope (permission not held) never allows. */
    private function scopeAllows(?Scope $scope, array $actingUser, array $task, array $assigneeIds, array $teams): bool
    {
        if ($scope === null) {
            return false;
        }
        if ($scope === Scope::ALL || $scope === Scope::ORGANIZATION) {
            return true;
        }

        $people = $this->people($task, $assigneeIds);
        if (in_array((int) $actingUser['id'], $people, true)) {
            return true; // OWN; TEAM and DEPARTMENT include it
        }

        $column = match ($scope) {
            Scope::TEAM => 'team_id',
            Scope::DEPARTMENT => 'department_id',
            default => null,
        };
        $mine = $column !== null && isset($actingUser[$column]) && $actingUser[$column] !== '' ? (int) $actingUser[$column] : null;
        if ($mine === null) {
            return false;
        }

        foreach ($people as $userId) {
            if (($teams[$userId][$column] ?? null) === $mine) {
                return true;
            }
        }

        return false;
    }

    /** A task related to a project is visible only to users who may see that project. */
    private function relatedAccessible(array $actingUser, array $task): bool
    {
        if (($task['related_type'] ?? null) !== 'project') {
            return true;
        }

        return $this->recordTypes->find('project', (int) ($task['related_id'] ?? 0), $actingUser) !== null;
    }

    private function requireNotArchived(array $task): void
    {
        if (($task['archived_at'] ?? null) !== null) {
            throw new ValidationException(['task' => [t('tasks.error.archived')]]);
        }
    }

    private function requireTask(int $id): array
    {
        $task = $this->tasks->findById($id);

        if ($task === null) {
            throw new NotFoundException('Task not found.');
        }

        return $task;
    }
}
