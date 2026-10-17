<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Services\TaskService;

use function App\Infrastructure\Theme\t;

final class TaskController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly TaskService $tasks,
    ) {
        parent::__construct($templates);
    }

    public function myTasks(Request $request): Response
    {
        $user = CurrentUser::user();
        $view = in_array($request->query('view'), TaskService::LIST_VIEWS, true) ? (string) $request->query('view') : 'mine';
        $status = in_array($request->query('status'), TaskService::STATUSES, true) ? (string) $request->query('status') : '';
        $tasks = $this->tasks->listFor($user, $view, $status !== '' ? $status : null);

        return $this->viewWithLayout('sales/tasks', 'layout', [
            'tasks' => $tasks,
            'users' => $this->tasks->assignableUsers((int) $user['organization_id']),
            'view' => $view,
            'canListAll' => $this->tasks->canListAll($user),
            'archived' => $view === 'archived',
            'statusFilter' => $status,
            'can' => $this->tasks->capabilities($user),
            'taskActions' => $this->tasks->actionsFor($user, $tasks),
        ]);
    }

    public function changeStatus(Request $request): Response
    {
        $status = (string) $request->input('status', '');

        try {
            $this->tasks->changeStatus(CurrentUser::user(), (int) $request->attribute('id'), $status);
            Session::setFlash('success', t('tasks.flash.status_changed', ['status' => t('tasks.status.' . $status)]));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect($this->backTo($request));
    }

    public function archive(Request $request): Response
    {
        $this->tasks->archive(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('tasks.flash.archived'));

        return $this->redirect($this->backTo($request));
    }

    public function unarchive(Request $request): Response
    {
        $this->tasks->unarchive(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('tasks.flash.unarchived'));

        return $this->redirect($this->backTo($request));
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $task = $this->tasks->find($user, $id);
        $assigneeIds = $this->tasks->assigneeIds($id);
        if (!empty($task['assigned_to_user_id'])) {
            $assigneeIds[] = (int) $task['assigned_to_user_id'];
        }
        // Active users, plus the task's current (possibly inactive) assignees.
        $users = $this->tasks->assignableUsers((int) $user['organization_id'], $assigneeIds);
        $mayDelete = fn (array $row): array => $row + ['can_delete' => $this->tasks->mayDeleteItem($user, $task, $row['user_id'] ?? null)];

        return $this->viewWithLayout('sales/task-show', 'layout', [
            'task' => $task,
            'users' => $users,
            'assignees' => array_values(array_filter($users, fn ($u) => in_array((int) $u['id'], $assigneeIds, true))),
            'comments' => array_map($mayDelete, $this->tasks->comments($id)),
            'attachments' => array_map($mayDelete, $this->tasks->attachments($id)),
            'actions' => $this->tasks->actionsFor($user, [$task])[$id],
            'errors' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->tasks->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', t('tasks.flash.added'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect($this->backTo($request));
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->update(CurrentUser::user(), $id, $request->all());
            Session::setFlash('success', t('tasks.flash.updated'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function toggleComplete(Request $request): Response
    {
        try {
            $this->tasks->toggleComplete(CurrentUser::user(), (int) $request->attribute('id'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect($this->backTo($request));
    }

    public function delete(Request $request): Response
    {
        $this->tasks->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('tasks.flash.deleted'));

        // The task page itself is gone now; never send the user back to it.
        $back = $this->backTo($request);

        return $this->redirect(preg_match('#^/tasks/\d+$#', $back) === 1 ? '/tasks' : $back);
    }

    public function addComment(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->addComment(CurrentUser::user(), $id, (string) $request->input('body', ''));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function deleteComment(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->deleteComment(CurrentUser::user(), $id, (int) $request->attribute('commentId'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function uploadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $file = $request->file('file');

        if ($file === null) {
            Session::setFlash('error', t('tasks.error.choose_file'));

            return $this->redirect('/tasks/' . $id);
        }

        try {
            $this->tasks->addAttachment(CurrentUser::user(), $id, $file);
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function deleteAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->deleteAttachment(CurrentUser::user(), $id, (int) $request->attribute('attachmentId'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function downloadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $attachmentId = (int) $request->attribute('attachmentId');

        // The task must be viewable by this user (403 otherwise) and the file
        // must be THIS task's (404 otherwise) — before any byte is served.
        $match = $this->tasks->attachmentForDownload(CurrentUser::user(), $id, $attachmentId);

        if ($match === null) {
            throw new NotFoundException();
        }

        $path = $this->tasks->attachmentPath($match);

        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', $match['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $match['filename']) . '"');
    }

    /** Services put translated messages in their ValidationExceptions. */
    private function firstError(ValidationException $e): string
    {
        $first = array_values($e->errors)[0][0] ?? null;

        return is_string($first) && $first !== '' ? $first : t('tasks.error.generic');
    }

    /**
     * Where a task action returns to. Only the task pages (list with its
     * view/status filter, one task) and a deal page are accepted — never
     * another host, a protocol-relative URL, or anything malformed.
     */
    private function backTo(Request $request): string
    {
        $returnTo = $request->input('return_to', '');

        return is_string($returnTo) && self::isSafeReturnTo($returnTo) ? $returnTo : '/tasks';
    }

    public static function isSafeReturnTo(string $path): bool
    {
        return preg_match('#^/(tasks(/\d+)?(\?[A-Za-z0-9_=&]*)?|deals/\d+)$#D', $path) === 1;
    }
}
