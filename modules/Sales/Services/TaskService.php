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
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Sales\TaskChecklistRepository;
use Modules\Sales\TaskLabelRepository;
use Modules\Sales\TaskRepository;
use Modules\Sales\TaskStatusRepository;

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

    /** Recurrence units (T7); every recur_interval days / weeks / months. */
    public const RECURRENCES = ['daily', 'weekly', 'monthly'];
    public const MAX_RECUR_INTERVAL = 365;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly NoteService $notes,
        private readonly AttachmentService $attachments,
        private readonly AuthorizationEngine $authorization,
        private readonly UserRepository $users,
        private readonly TaskChecklistRepository $checklist,
        private readonly TaskRelatedRecords $related,
        private readonly TaskLabelRepository $labels,
        private ?TaskStatusService $statuses = null,
    ) {
    }

    // --- Custom statuses (T8) ------------------------------------------------

    /** The legacy value's lifecycle category (what an untouched task's tasks.status means). */
    private const LEGACY_CATEGORY = [
        'pending' => 'open', 'in_progress' => 'in_progress', 'on_hold' => 'in_progress',
        'completed' => 'completed', 'rejected' => 'cancelled', 'cancelled' => 'cancelled',
    ];

    public function statuses(): TaskStatusService
    {
        return $this->statuses ??= new TaskStatusService(new TaskStatusRepository(), $this->authorization);
    }

    /** @return array<int, array> the current organization's statuses by id (for display) */
    public function statusMap(): array
    {
        return $this->statuses()->byId();
    }

    public function defaultStatusId(string $category): ?int
    {
        $status = $this->statuses()->defaultFor($category);

        return $status !== null ? (int) $status['id'] : null;
    }

    /** Statuses a new task may start in: the active open and in-progress ones. */
    public function createStatusOptions(): array
    {
        return array_values(array_filter($this->statuses()->active(), fn (array $s) => in_array($s['category'], ['open', 'in_progress'], true)));
    }

    /** Moves a task to one of the organization's custom statuses (the row menu, the edit form). */
    public function changeStatusTo(array $actingUser, int $id, int $statusId): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_VIEW, $task);
        $this->requireNotArchived($task);
        $target = $this->statuses()->find($statusId);
        if ($target === null) {
            throw new ValidationException(['status' => [t('tasks.invalid_status')]]);
        }
        $fields = $this->statusChange($actingUser, $task, $target, 'status');
        if ($fields !== []) {
            $this->tasks->updateTask($id, $fields);
        }

        return $this->tasks->findById($id);
    }

    /**
     * The columns that move $task to $target, after every check: the target
     * is active; tasks.change_status on the task; a status with the same
     * legacy value is just a relabel, otherwise the existing workflow decides
     * (TRANSITIONS on the legacy value, tasks.reopen to leave a closed one);
     * an archived-category status archives the task and needs tasks.archive.
     * [] = it already has that status.
     */
    private function statusChange(array $actingUser, array $task, array $target, string $errorKey): array
    {
        $current = TaskStatusService::effective($task, $this->statusMap());
        if ($current !== null && (int) $current['id'] === (int) $target['id']) {
            return [];
        }
        if ((int) $target['is_active'] !== 1) {
            throw new ValidationException([$errorKey => [t('tasks.invalid_status')]]);
        }
        $this->authorizeRecord($actingUser, Permission::TASKS_CHANGE_STATUS, $task);

        if ($target['category'] === 'archived') {
            $this->authorizeRecord($actingUser, Permission::TASKS_ARCHIVE, $task);

            return ['status_id' => (int) $target['id'], 'archived_at' => date('Y-m-d H:i:s'), 'archived_by_user_id' => (int) $actingUser['id']];
        }

        $legacy = (string) TaskStatusService::legacyFor($target);
        if ($legacy !== $task['status']) {
            if (!in_array($legacy, self::TRANSITIONS[$task['status']] ?? [], true)) {
                throw new ValidationException([$errorKey => [t('tasks.error.invalid_transition')]]);
            }
            if (in_array($task['status'], self::REOPEN_FROM, true)) {
                $this->authorizeRecord($actingUser, Permission::TASKS_REOPEN, $task);
            }
        }

        return [
            'status_id' => (int) $target['id'],
            'status' => $legacy,
            'completed_at' => $legacy === 'completed' ? ($task['completed_at'] ?? date('Y-m-d H:i:s')) : null,
        ];
    }

    /** @return array{status_id: ?int, status: string} a new task's status: the chosen one (active, open or in progress) or the default */
    private function initialStatus(array $input): array
    {
        $chosen = $input['status_id'] ?? '';
        if ($chosen !== '' && $chosen !== null) {
            $status = is_scalar($chosen) && ctype_digit((string) $chosen) ? $this->statuses()->find((int) $chosen) : null;
            if ($status === null || (int) $status['is_active'] !== 1 || !in_array($status['category'], ['open', 'in_progress'], true)) {
                throw new ValidationException(['status_id' => [t('tasks.invalid_status')]]);
            }
        } else {
            $status = $this->statuses()->defaultFor('open');
        }

        return $status !== null
            ? ['status_id' => (int) $status['id'], 'status' => (string) TaskStatusService::legacyFor($status)]
            : ['status_id' => null, 'status' => 'pending'];
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
        $criteria = $this->listCriteria($actingUser, $view, $status, '');

        return $this->listPage($actingUser, $criteria, 500, 0);
    }

    /**
     * The task list's query for one view, status filter and search text —
     * then countList() / listPage() (database-side paging, as on Contacts).
     * Every view already lies inside the viewer's tasks.view scope: mine,
     * created and archived are the viewer's own tasks; "all" needs
     * organization-wide scope. Project/contract tasks whose record the
     * viewer may not see are excluded here, in SQL, so counts and pages
     * never include them.
     */
    public function listCriteria(array $actingUser, string $view, ?string $status, string $search, ?int $statusId = null, ?int $labelId = null): array
    {
        $this->authorize($actingUser, Permission::TASKS_VIEW);
        $view = in_array($view, self::LIST_VIEWS, true) ? $view : 'mine';
        if ($view === 'all' && !$this->canListAll($actingUser)) {
            throw new AuthorizationException();
        }

        $criteria = [
            'view' => $view,
            'user_id' => (int) $actingUser['id'],
            'status' => in_array($status, self::STATUSES, true) ? $status : null,
            'search' => mb_substr(trim($search), 0, 100),
        ];
        // A custom status of this organization only (another's id finds nothing).
        $filter = $statusId !== null ? $this->statuses()->find($statusId) : null;
        if ($filter !== null) {
            $criteria['status_filter'] = ['id' => (int) $filter['id'], 'system_key' => $filter['system_key']];
        }
        // A label of this organization only (T9).
        if ($labelId !== null && $this->labels->findById($labelId) !== null) {
            $criteria['label_id'] = $labelId;
        }

        $hidden = [];
        foreach ($this->tasks->listRelations($criteria) as $row) {
            if (!$this->relatedAccessible($actingUser, $row)) {
                $hidden[] = (int) $row['id'];
            }
        }
        $criteria['exclude'] = $hidden;

        return $criteria;
    }

    /**
     * The calendar's tasks (T9): the list's criteria (already authorized by
     * listCriteria()) within a local wall-clock range, re-checked row by row
     * like the list pages.
     */
    public function calendarTasks(array $actingUser, array $criteria, string $from, string $to, int $limit = 1000): array
    {
        return $this->visibleRows($actingUser, $this->tasks->calendarList($criteria, $from, $to, $limit));
    }

    public function countList(array $criteria): int
    {
        return $this->tasks->countList($criteria);
    }

    /** One page; visibleRows() re-checks every row as a second guard. */
    public function listPage(array $actingUser, array $criteria, int $limit, int $offset): array
    {
        return $this->visibleRows($actingUser, $this->tasks->pageList($criteria, $limit, $offset));
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
        $statusMap = $tasks !== [] ? $this->statusMap() : [];

        $result = [];
        foreach ($tasks as $task) {
            $id = (int) $task['id'];
            $relatedOk = $this->relatedAccessible($actingUser, $task); // once per task
            $may = fn (string $permission): bool => $this->scopeAllows($scopes[$permission], $actingUser, $task, $assignees[$id] ?? [], $teams)
                && $this->scopeAllows($scopes[Permission::TASKS_VIEW], $actingUser, $task, $assignees[$id] ?? [], $teams)
                && $relatedOk;
            $active = ($task['archived_at'] ?? null) === null;

            $statuses = [];
            if ($active && $may(Permission::TASKS_CHANGE_STATUS)) {
                $reopenOk = !in_array($task['status'], self::REOPEN_FROM, true) || $may(Permission::TASKS_REOPEN);
                $statuses = $reopenOk ? (self::TRANSITIONS[$task['status']] ?? []) : [];
            }
            $toggleTo = $task['status'] === 'completed' ? 'pending' : 'completed';

            // The organization's statuses this task may move to (T8), by the rules statusChange() enforces.
            $statusOptions = [];
            if ($active && $may(Permission::TASKS_CHANGE_STATUS)) {
                $current = TaskStatusService::effective($task, $statusMap);
                $reopenOk = !in_array($task['status'], self::REOPEN_FROM, true) || $may(Permission::TASKS_REOPEN);
                foreach ($statusMap as $option) {
                    if ((int) $option['is_active'] !== 1 || ($current !== null && (int) $current['id'] === (int) $option['id'])) {
                        continue;
                    }
                    $allowed = match (true) {
                        $option['category'] === 'archived' => $may(Permission::TASKS_ARCHIVE),
                        TaskStatusService::legacyFor($option) === $task['status'] => true,
                        default => $reopenOk && in_array(TaskStatusService::legacyFor($option), self::TRANSITIONS[$task['status']] ?? [], true),
                    };
                    if ($allowed) {
                        $statusOptions[] = $option;
                    }
                }
            }

            $result[$id] = [
                'statuses' => $statuses,
                'status_options' => $statusOptions,
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

    /** Whether this user may see this task (the one access rule; reminders and their notifications use it too). */
    public function canView(array $user, array $task): bool
    {
        return $this->allows($user, Permission::TASKS_VIEW, $task);
    }

    /**
     * A recurring task's neighbours in its series (T7): the occurrence it was
     * generated from and the one generated from it — ids only, and only those
     * this user may see.
     *
     * @return array{previous: ?int, next: ?int}
     */
    public function seriesLinks(array $user, array $task): array
    {
        $visible = fn (?array $row): ?int => $row !== null && $this->canView($user, $row) ? (int) $row['id'] : null;

        return [
            'previous' => !empty($task['recur_parent_id']) ? $visible($this->tasks->findById((int) $task['recur_parent_id'])) : null,
            'next' => $visible($this->tasks->occurrenceAfter((int) $task['id'])),
        ];
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
        $schedule = $this->validatedSchedule($input);
        [$relatedType, $relatedId] = $this->validatedRelated($actingUser, $input);
        $assigneeIds = $this->validatedAssigneeIds($input, (int) $actingUser['id']);
        $status = $this->initialStatus($input);

        $fields = $status + [
            'title' => $title,
            'description' => $this->orNull($input, 'description'),
            'priority' => in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true) ? $input['priority'] : 'medium',
            'assigned_to_user_id' => $assigneeIds[0],
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'created_by_user_id' => (int) $actingUser['id'],
        ] + $schedule + $this->validatedRecurrence($input, null, $schedule);

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
     * description, start or due date clears it. The related record is fixed at
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
        $fields += $this->validatedSchedule($input, $task);
        $fields += $this->validatedRecurrence($input, $task, $fields);
        // The related record changes only through the selector: absent or "keep" =
        // unchanged, "" = none, "type:id" = validated like on create. (The older
        // related_type / related_id pair is not read here.)
        if (array_key_exists('related', $input) && $input['related'] !== 'keep') {
            [$fields['related_type'], $fields['related_id']] = $this->validatedRelated($actingUser, ['related' => $input['related']]);
        }
        if (in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true)) {
            $fields['priority'] = $input['priority'];
        }
        // The edit form's status (T8): the same rules as the status menu.
        if (isset($input['status_id']) && $input['status_id'] !== '') {
            $target = is_scalar($input['status_id']) && ctype_digit((string) $input['status_id']) ? $this->statuses()->find((int) $input['status_id']) : null;
            if ($target === null) {
                throw new ValidationException(['status_id' => [t('tasks.invalid_status')]]);
            }
            $fields += $this->statusChange($actingUser, $task, $target, 'status_id');
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
        $target = $this->statuses()->defaultFor($task['status'] === 'completed' ? 'open' : 'completed');
        if ($target === null) {
            return $this->changeStatus($actingUser, $id, $task['status'] === 'completed' ? 'pending' : 'completed');
        }

        return $this->changeStatusTo($actingUser, $id, (int) $target['id']);
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

        $system = null;
        foreach ($this->statusMap() as $row) {
            if ($row['system_key'] === $status) {
                $system = $row;
            }
        }
        $this->tasks->updateTask($id, [
            'status' => $status,
            'status_id' => $system !== null ? (int) $system['id'] : $this->defaultStatusId(self::LEGACY_CATEGORY[$status]),
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

        $fields = ['archived_at' => null, 'archived_by_user_id' => null];
        // Archived through an "archived" status: restored, it shows its own legacy status again.
        $current = !empty($task['status_id']) ? ($this->statusMap()[(int) $task['status_id']] ?? null) : null;
        if ($current !== null && $current['category'] === 'archived') {
            $fields['status_id'] = null;
        }
        $this->tasks->updateTask($id, $fields);

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

    // --- Checklist (T3) ---------------------------------------------------
    // Reading needs what the task page needs (find(): tasks.view + scope);
    // every change needs tasks.edit on the task — the same rule as editing
    // the task (OWN = its creator or an assignee) — and an active task.

    /** The task's items; the caller has already loaded the task with find(). */
    public function checklist(int $taskId): array
    {
        return $this->checklist->forTask($taskId);
    }

    /** @return array{done: int, total: int} */
    public function checklistProgress(int $taskId): array
    {
        return $this->checklist->progressFor([$taskId])[$taskId] ?? ['done' => 0, 'total' => 0];
    }

    /**
     * Done / total for list rows; tasks without items are absent.
     *
     * @return array<int, array{done: int, total: int}>
     */
    public function checklistProgressFor(array $tasks): array
    {
        return $this->checklist->progressFor(array_map(fn (array $t) => (int) $t['id'], $tasks));
    }

    public function addChecklistItem(array $actingUser, int $taskId, mixed $title): array
    {
        $this->editableTask($actingUser, $taskId);
        $id = $this->checklist->create($taskId, $this->validatedChecklistTitle($title), (int) $actingUser['id']);

        return $this->checklist->findForTask($taskId, $id);
    }

    public function renameChecklistItem(array $actingUser, int $taskId, int $itemId, mixed $title): array
    {
        $this->editableTask($actingUser, $taskId);
        $this->checklistItem($taskId, $itemId);
        $this->checklist->updateItem($taskId, $itemId, ['title' => $this->validatedChecklistTitle($title)]);

        return $this->checklist->findForTask($taskId, $itemId);
    }

    /** Sets (not flips) the state, so a repeated click or a retry is harmless. */
    public function setChecklistItemDone(array $actingUser, int $taskId, int $itemId, bool $done): array
    {
        $this->editableTask($actingUser, $taskId);
        $item = $this->checklistItem($taskId, $itemId);

        if ((bool) $item['is_done'] !== $done) {
            $this->checklist->updateItem($taskId, $itemId, [
                'is_done' => $done ? 1 : 0,
                'completed_at' => $done ? date('Y-m-d H:i:s') : null,
                'completed_by_user_id' => $done ? (int) $actingUser['id'] : null,
            ]);
        }

        return $this->checklist->findForTask($taskId, $itemId);
    }

    public function deleteChecklistItem(array $actingUser, int $taskId, int $itemId): void
    {
        $this->editableTask($actingUser, $taskId);
        $this->checklistItem($taskId, $itemId);
        $this->checklist->deleteItem($taskId, $itemId);
    }

    /** The task (404 when missing, deleted or another organization's), tasks.edit on it, not archived. */
    private function editableTask(array $actingUser, int $taskId): array
    {
        $task = $this->requireTask($taskId);
        $this->authorizeRecord($actingUser, Permission::TASKS_EDIT, $task);
        $this->requireNotArchived($task);

        return $task;
    }

    private function checklistItem(int $taskId, int $itemId): array
    {
        return $this->checklist->findForTask($taskId, $itemId) ?? throw new NotFoundException();
    }

    private function validatedChecklistTitle(mixed $title): string
    {
        $title = is_string($title) ? trim($title) : '';

        if ($title === '') {
            throw new ValidationException(['checklist_title' => [t('tasks.error.checklist_title_required')]]);
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new ValidationException(['checklist_title' => [t('tasks.error.checklist_title_too_long')]]);
        }

        return $title;
    }

    // --- Labels (T4) ----------------------------------------------------------

    /**
     * Labels per task for list rows / the task page.
     *
     * @return array<int, array[]>
     */
    public function labelsFor(array $tasks): array
    {
        return $this->labels->labelsForTasks(array_map(fn (array $t) => (int) $t['id'], $tasks));
    }

    /**
     * Replaces the task's labels. Needs tasks.edit on an active task; every
     * id must be a label of this organization — anything else rejects the
     * whole change (nothing is dropped silently).
     */
    public function setLabels(array $actingUser, int $taskId, mixed $labelIds): void
    {
        $this->editableTask($actingUser, $taskId);

        $values = is_array($labelIds) ? $labelIds : ($labelIds === null || $labelIds === '' ? [] : [$labelIds]);
        $ids = [];
        foreach ($values as $value) {
            $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
            if ($id <= 0 || $this->labels->findById($id) === null) {
                throw new ValidationException(['labels' => [t('tasks.error.label_invalid')]]);
            }
            $ids[] = $id;
        }

        $this->labels->setForTask($taskId, array_values(array_unique($ids)));
    }

    // --- Related record (T5) ---------------------------------------------------

    /**
     * The related record of each task, as this user may see it
     * (TaskRelatedRecords::resolve()); tasks without one are absent.
     *
     * @return array<int, array{state: string, type: string, id: int, label: ?string, url: ?string}>
     */
    public function relatedFor(array $actingUser, array $tasks): array
    {
        $result = [];
        foreach ($tasks as $task) {
            if (!empty($task['related_type']) && !empty($task['related_id'])) {
                $result[(int) $task['id']] = $this->related->resolve($actingUser, (string) $task['related_type'], (int) $task['related_id']);
            }
        }

        return $result;
    }

    /** Records the user may pick in the task forms ("type:id" => label, per type). */
    public function relatedOptions(array $actingUser): array
    {
        return $this->related->options($actingUser);
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
     * Start, due and reminder: each a date (start_at / due_at / remind_at,
     * YYYY-MM-DD as the datepicker sends it) and an optional time
     * (start_time / due_time / remind_time, HH:MM from <input type="time">).
     * All are the organization's local wall clock (C3) — stored as typed,
     * never converted. A reminder date without a time is due from 00:00
     * that day; a reminder may be before, on or after the due date.
     *
     *  - empty date: none (a time without a date is an error);
     *  - date, no time: all day (*_all_day = 1, time part 00:00:00);
     *  - date + time: timed (*_all_day = 0) — an explicit 00:00 is midnight;
     *  - a date already carrying a time ("Y-m-d H:i[:s]", API callers) is
     *    timed; together with a separate time it is rejected as ambiguous.
     * Start may not be after due: compared with times when both are timed,
     * otherwise by date. Only submitted dates change; on update the check
     * uses the stored value for the other one.
     *
     * @return array<string, mixed> columns to write
     */
    private function validatedSchedule(array $input, ?array $current = null): array
    {
        $fields = [];
        $errors = [];

        foreach (['start' => 'start_at', 'due' => 'due_at', 'remind' => 'remind_at'] as $prefix => $dateKey) {
            $timeKey = $prefix . '_time';
            $time = $input[$timeKey] ?? null;

            if (!array_key_exists($dateKey, $input)) {
                if (is_string($time) && trim($time) !== '' || is_array($time)) {
                    $errors[$timeKey] = [t('tasks.error.time_without_date')];
                }
                continue;
            }

            try {
                [$value, $allDay] = $this->schedulePoint($input[$dateKey], $time, $dateKey, $timeKey);
                $fields[$dateKey] = $value;
                $fields[$prefix . '_all_day'] = $allDay ? 1 : 0;
            } catch (ValidationException $e) {
                $errors += $e->errors;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $merged = fn (string $prefix): array => array_key_exists($prefix . '_at', $fields)
            ? [$fields[$prefix . '_at'], (bool) $fields[$prefix . '_all_day']]
            : [$current[$prefix . '_at'] ?? null, (bool) ($current[$prefix . '_all_day'] ?? true)];
        [$start, $startAllDay] = $merged('start');
        [$due, $dueAllDay] = $merged('due');

        if ($start !== null && $due !== null) {
            $timed = !$startAllDay && !$dueAllDay;
            if (($timed ? $start : substr($start, 0, 10)) > ($timed ? $due : substr($due, 0, 10))) {
                throw new ValidationException(['start_at' => [t('tasks.error.start_after_due')]]);
            }
        }

        // A new or changed reminder is a new occurrence: it fires (again) once.
        if (array_key_exists('remind_at', $fields)) {
            $changed = $fields['remind_at'] !== ($current['remind_at'] ?? null)
                || (int) $fields['remind_all_day'] !== (int) ($current['remind_all_day'] ?? 1);
            if ($fields['remind_at'] !== null && $changed) {
                $fields['remind_seq'] = (int) ($current['remind_seq'] ?? 0) + 1;
            }
        }

        return $fields;
    }

    /**
     * The email reminder flag and the recurrence rule (T7).
     *
     * remind_email: the forms send a hidden 0 before the checkbox; absent =
     * unchanged. It only stays on while the task has a reminder.
     *
     * Recurrence: recur_type daily / weekly / monthly ('' = none), every
     * recur_interval (1–365) units, until recur_until (optional local date,
     * not before the task's own date), recur_active (forms: hidden 0 +
     * checkbox; a new rule is active). A recurring task needs a start or
     * due date — the series repeats from it (recur_anchor). A new or changed
     * rule or date re-bases the series on this task (recur_index 0).
     *
     * @param array $fields the schedule columns being written
     * @return array<string, mixed> columns to write
     */
    private function validatedRecurrence(array $input, ?array $current, array $fields): array
    {
        $out = [];
        $value = fn (string $key) => array_key_exists($key, $fields) ? $fields[$key] : ($current[$key] ?? null);
        $truthy = fn (mixed $v): bool => in_array($v, ['1', 1, true, 'on'], true);

        $remindAt = $value('remind_at');
        if (array_key_exists('remind_email', $input)) {
            $out['remind_email'] = $remindAt !== null && $truthy($input['remind_email']) ? 1 : 0;
        } elseif ($remindAt === null && !empty($current['remind_email'])) {
            $out['remind_email'] = 0;
        }

        $errors = [];
        if (array_key_exists('recur_type', $input)) {
            $type = is_string($input['recur_type']) ? trim($input['recur_type']) : false;
            if ($type === '') {
                return $out + ($current === null || $current['recur_type'] !== null
                    ? ['recur_type' => null, 'recur_interval' => 1, 'recur_until' => null, 'recur_active' => 0, 'recur_anchor' => null, 'recur_index' => 0]
                    : []);
            }
            if (!in_array($type, self::RECURRENCES, true)) {
                $errors['recur_type'] = [t('tasks.error.invalid_recurrence')];
            }
            $rawInterval = $input['recur_interval'] ?? '1';
            $rawInterval = is_int($rawInterval) ? (string) $rawInterval : (is_string($rawInterval) ? trim($rawInterval) : '');
            $interval = preg_match('/^\d{1,3}$/D', $rawInterval) === 1 ? (int) $rawInterval : 0;
            if ($interval < 1 || $interval > self::MAX_RECUR_INTERVAL) {
                $errors['recur_interval'] = [t('tasks.error.invalid_recur_interval')];
            }
            $rawUntil = $input['recur_until'] ?? '';
            $until = null;
            if (!is_string($rawUntil)) {
                $errors['recur_until'] = [t('tasks.error.invalid_recur_until')];
            } elseif (trim($rawUntil) !== '') {
                $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($rawUntil));
                if ($day === false || $day->format('Y-m-d') !== trim($rawUntil)) {
                    $errors['recur_until'] = [t('tasks.error.invalid_recur_until')];
                } else {
                    $until = trim($rawUntil);
                }
            }
            $active = array_key_exists('recur_active', $input)
                ? ($truthy($input['recur_active']) ? 1 : 0)
                : ($current !== null && $current['recur_type'] !== null ? (int) $current['recur_active'] : 1);
        } else {
            $type = $current['recur_type'] ?? null;
            if ($type === null) {
                return $out;
            }
            $interval = (int) $current['recur_interval'];
            $until = $current['recur_until'];
            $active = (int) $current['recur_active'];
        }

        $anchor = $value('start_at') ?? $value('due_at');
        if ($anchor === null && !isset($errors['recur_type'])) {
            $errors['recur_type'] = [t('tasks.error.recur_needs_date')];
        }
        if ($until !== null && $anchor !== null && !isset($errors['recur_until']) && $until < substr((string) $anchor, 0, 10)) {
            $errors['recur_until'] = [t('tasks.error.recur_until_before')];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $out += ['recur_type' => $type, 'recur_interval' => $interval, 'recur_until' => $until, 'recur_active' => $active];
        $ownAnchor = $current !== null ? ($current['start_at'] ?? $current['due_at'] ?? null) : null;
        if ($current === null || $current['recur_type'] !== $type || (int) $current['recur_interval'] !== $interval
            || $current['recur_anchor'] === null || $ownAnchor !== $anchor) {
            $out['recur_anchor'] = $anchor;
            $out['recur_index'] = 0;
        }

        return $out;
    }

    /** @return array{0: ?string, 1: bool} the stored value ("Y-m-d H:i:s" or null) and whether it is all day */
    private function schedulePoint(mixed $date, mixed $time, string $dateKey, string $timeKey): array
    {
        $invalidDate = [$dateKey => [t(match ($dateKey) {
            'start_at' => 'tasks.error.invalid_start_date',
            'remind_at' => 'tasks.error.invalid_remind_date',
            default => 'tasks.error.invalid_due_date',
        })]];
        $date = is_string($date) ? trim($date) : $date;
        $time = is_string($time) ? trim($time) : $time;
        $hasTime = $time !== null && $time !== '';

        if ($date === null || $date === '') {
            if ($hasTime) {
                throw new ValidationException([$timeKey => [t('tasks.error.time_without_date')]]);
            }

            return [null, true];
        }
        if (!is_string($date)) {
            throw new ValidationException($invalidDate);
        }

        if ($hasTime) {
            if (!is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) !== 1) {
                throw new ValidationException([$timeKey => [t('tasks.error.invalid_time')]]);
            }
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($day === false || $day->format('Y-m-d') !== $date) {
                throw new ValidationException($invalidDate);
            }

            return [$date . ' ' . $time . ':00', false];
        }

        foreach (['!Y-m-d' => 'Y-m-d', '!Y-m-d H:i' => 'Y-m-d H:i', '!Y-m-d H:i:s' => 'Y-m-d H:i:s'] as $format => $echo) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date);
            if ($parsed !== false && $parsed->format($echo) === $date) {
                return [$parsed->format('Y-m-d H:i:s'), $format === '!Y-m-d'];
            }
        }

        throw new ValidationException($invalidDate);
    }

    /**
     * At most one related record, from the form's selector (`related` =
     * "type:id", '' = none) or the older related_type + related_id pair
     * (the deal page's quick add, API callers). The record must exist in
     * this organization and be visible to the user by its own module's
     * rule (TaskRelatedRecords); missing and forbidden give the same error,
     * so nothing about an inaccessible record is revealed.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function validatedRelated(array $actingUser, array $input): array
    {
        if (isset($input['related']) && $input['related'] !== '') {
            $value = $input['related'];
            $parts = is_string($value) ? explode(':', $value, 2) : [];
            $type = $parts[0] ?? '';
            $rawId = $parts[1] ?? '';
            $errorKey = 'related';
        } else {
            $type = $input['related_type'] ?? null;
            if ($type === null || $type === '') {
                return [null, null];
            }
            $rawId = $input['related_id'] ?? null;
            $errorKey = 'related_type';
        }

        $id = is_int($rawId) ? $rawId : (is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : 0);

        if (!is_string($type) || !in_array($type, TaskRelatedRecords::TYPES, true) || $id <= 0
            || $this->related->resolve($actingUser, $type, $id)['state'] !== 'ok') {
            throw new ValidationException([$errorKey => [t('tasks.error.invalid_related')]]);
        }

        return [$type, $id];
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

    /**
     * A task related to a project or a contract is visible only to users who
     * may see that record (Projects: permission scope + membership). When the
     * record no longer exists the task stays visible and shows it as deleted.
     */
    private function relatedAccessible(array $actingUser, array $task): bool
    {
        $type = $task['related_type'] ?? null;
        if ($type !== 'project' && $type !== 'contract') {
            return true;
        }

        return $this->related->resolve($actingUser, $type, (int) ($task['related_id'] ?? 0))['state'] !== 'forbidden';
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
