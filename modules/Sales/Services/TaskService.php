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
use App\Infrastructure\Validation\Validator;
use Modules\Sales\TaskRepository;

/**
 * Comments and attachments deliberately reuse Phase 5's shared
 * NoteService/AttachmentService (with subject type 'task') rather than
 * inventing a separate "comment" concept — a comment IS a note, and a
 * task's attachments work exactly like a contact's or company's.
 */
final class TaskService
{
    private const ALLOWED_RELATED_TYPES = ['contact', 'company', 'lead', 'deal'];

    /** Workflow order (also the order shown in the UI). Archiving is a separate flag, not a status. */
    public const STATUSES = ['pending', 'in_progress', 'on_hold', 'completed', 'rejected', 'cancelled'];
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly NoteService $notes,
        private readonly AttachmentService $attachments,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function forSubject(string $relatedType, int $relatedId): array
    {
        return $this->tasks->forSubject($relatedType, $relatedId);
    }

    public function upcomingForUser(int $userId): array
    {
        return $this->tasks->upcomingForUser($userId);
    }

    /** "My tasks": all statuses (finished ones stay visible), or the archive. */
    public function forUser(array $actingUser, bool $archived, ?string $status = null): array
    {
        $this->authorize($actingUser, Permission::TASKS_VIEW);

        return $this->tasks->forUser((int) $actingUser['id'], $archived, in_array($status, self::STATUSES, true) ? $status : null);
    }

    /**
     * Blanket (list-level) capabilities for templates, so a button is
     * only shown when the action would be allowed. The services still
     * re-check every action against the specific task.
     *
     * @return array{change_status: bool, archive: bool, delete: bool, edit: bool}
     */
    public function capabilities(array $actingUser): array
    {
        return [
            'change_status' => $this->authorization->can($actingUser, Permission::TASKS_CHANGE_STATUS),
            'archive' => $this->authorization->can($actingUser, Permission::TASKS_ARCHIVE),
            'delete' => $this->authorization->can($actingUser, Permission::TASKS_DELETE),
            'edit' => $this->authorization->can($actingUser, Permission::TASKS_EDIT),
        ];
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

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::TASKS_CREATE);

        $validator = Validator::make($input, ['title' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $relatedType = $input['related_type'] ?? null;
        if ($relatedType !== null && $relatedType !== '' && !in_array($relatedType, self::ALLOWED_RELATED_TYPES, true)) {
            throw new ValidationException(['related_type' => ['Invalid related record type.']]);
        }
        if ($relatedType === '') {
            $relatedType = null;
        }

        $assigneeIds = $this->assigneeIdsFromInput($input, (int) $actingUser['id']);

        $fields = [
            'title' => trim((string) $input['title']),
            'description' => $this->orNull($input, 'description'),
            'due_at' => $this->orNull($input, 'due_at'),
            'priority' => in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true) ? $input['priority'] : 'medium',
            'assigned_to_user_id' => $assigneeIds[0],
            'related_type' => $relatedType,
            'related_id' => $relatedType !== null ? (int) ($input['related_id'] ?? 0) : null,
            'created_by_user_id' => (int) $actingUser['id'],
        ];

        $id = $this->tasks->create($fields);
        $this->tasks->setAssignees($id, $assigneeIds);

        return $this->tasks->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_EDIT, $task);

        $validator = Validator::make($input, ['title' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $fields = array_filter([
            'title' => trim((string) $input['title']),
            'description' => $this->orNull($input, 'description'),
            'due_at' => $this->orNull($input, 'due_at'),
            'priority' => in_array($input['priority'] ?? null, ['low', 'medium', 'high'], true) ? $input['priority'] : null,
        ], fn ($v) => $v !== null);

        $this->tasks->updateTask($id, $fields);

        if (isset($input['assigned_to_user_ids'])) {
            $assigneeIds = $this->assigneeIdsFromInput($input, (int) $actingUser['id']);
            $this->tasks->setAssignees($id, $assigneeIds);
            $this->tasks->updateTask($id, ['assigned_to_user_id' => $assigneeIds[0]]);
        }

        return $this->tasks->findById($id);
    }

    /** The list checkbox: done ↔ pending. The task stays in the list (struck through when done). */
    public function toggleComplete(array $actingUser, int $id): array
    {
        $task = $this->requireTask($id);

        return $this->changeStatus($actingUser, $id, $task['status'] === 'completed' ? 'pending' : 'completed');
    }

    public function changeStatus(array $actingUser, int $id, string $status): array
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_CHANGE_STATUS, $task);

        if (!in_array($status, self::STATUSES, true)) {
            throw new ValidationException(['status' => ['tasks.invalid_status']]);
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

    public function delete(array $actingUser, int $id): void
    {
        $task = $this->requireTask($id);
        $this->authorizeRecord($actingUser, Permission::TASKS_DELETE, $task);

        $this->tasks->deleteTask($id);
    }

    // --- Comments (Notes reused, subject type 'task') -------------------

    public function comments(int $taskId): array
    {
        return $this->notes->forSubject('task', $taskId);
    }

    public function addComment(array $actingUser, int $taskId, string $body): array
    {
        $task = $this->requireTask($taskId);
        $this->authorizeRecord($actingUser, Permission::TASKS_VIEW, $task);

        return $this->notes->add('task', $taskId, (int) $actingUser['id'], $body);
    }

    /** The task must exist (in the user's organization) and be viewable; the note must be THIS task's. */
    public function deleteComment(array $actingUser, int $taskId, int $noteId): void
    {
        $this->find($actingUser, $taskId);
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

        return $this->attachments->upload('task', $taskId, (int) $actingUser['organization_id'], (int) $actingUser['id'], $file);
    }

    /** The task must exist (in the user's organization) and be viewable; the file must be THIS task's. */
    public function deleteAttachment(array $actingUser, int $taskId, int $attachmentId): void
    {
        $this->find($actingUser, $taskId);
        $this->attachments->deleteFromSubject('task', $taskId, $attachmentId);
    }

    /**
     * The attachment for a download, only when the user may view the task
     * (find(): tasks.view + the OWN creator/assignee rule — the task page's
     * own check) AND the file really belongs to this task. Authorization
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

    /**
     * @return int[] at least one id — falls back to the acting user
     *   if nothing was submitted, so a task is never left unassigned.
     */
    private function assigneeIdsFromInput(array $input, int $fallbackUserId): array
    {
        $raw = $input['assigned_to_user_ids'] ?? $input['assigned_to_user_id'] ?? null;

        if ($raw === null || $raw === '') {
            return [$fallbackUserId];
        }

        $ids = is_array($raw) ? $raw : [$raw];
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn ($v) => $v !== ''))));

        return $ids !== [] ? $ids : [$fallbackUserId];
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    /**
     * Handles multi-assignee 'own' scope explicitly rather than
     * relying on AuthorizationEngine::can()'s generic single-owner_id
     * check: a task can have several assignees, and "own" should mean
     * "I'm one of them" (or I created it), not just "I'm the primary
     * one". ALL/ORGANIZATION scope still goes through the engine
     * normally — this only changes OWN's meaning for tasks.
     */
    private function authorizeRecord(array $actingUser, string $permission, array $task): void
    {
        $scope = $this->authorization->scopeFor($actingUser, $permission);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        if ($scope === Scope::ALL || $scope === Scope::ORGANIZATION) {
            return;
        }

        if ($scope === Scope::OWN) {
            $userId = (int) $actingUser['id'];
            $isAssignee = $userId === (int) $task['assigned_to_user_id']
                || in_array($userId, $this->tasks->assigneeIds((int) $task['id']), true);
            $isCreator = $userId === (int) ($task['created_by_user_id'] ?? 0);

            if ($isAssignee || $isCreator) {
                return;
            }
        }

        throw new AuthorizationException();
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
