<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Services\TaskService;

use function App\Infrastructure\Theme\t;

final class TaskController extends BaseController
{
    private const ALLOWED_TYPES = ['contact', 'company', 'lead', 'deal'];

    public function __construct(
        TemplateEngine $templates,
        private readonly TaskService $tasks,
        private readonly UserRepository $users,
    ) {
        parent::__construct($templates);
    }

    public function myTasks(Request $request): Response
    {
        $user = CurrentUser::user();
        $archived = $request->query('view') === 'archived';
        $status = in_array($request->query('status'), TaskService::STATUSES, true) ? (string) $request->query('status') : '';

        return $this->viewWithLayout('sales/tasks', 'layout', [
            'tasks' => $this->tasks->forUser($user, $archived, $status !== '' ? $status : null),
            'users' => $this->users->findAllInOrganization((int) $user['organization_id']),
            'archived' => $archived,
            'statusFilter' => $status,
            'can' => $this->tasks->capabilities($user),
        ]);
    }

    public function changeStatus(Request $request): Response
    {
        $status = (string) $request->input('status', '');

        try {
            $this->tasks->changeStatus(CurrentUser::user(), (int) $request->attribute('id'), $status);
            Session::setFlash('success', t('tasks.flash.status_changed', ['status' => t('tasks.status.' . $status)]));
        } catch (ValidationException) {
            Session::setFlash('error', t('tasks.invalid_status'));
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
        $id = (int) $request->attribute('id');
        $task = $this->tasks->find(CurrentUser::user(), $id);
        $organizationId = (int) CurrentUser::user()['organization_id'];
        $allUsers = $this->users->findAllInOrganization($organizationId);
        $assigneeIds = $this->tasks->assigneeIds($id);

        return $this->viewWithLayout('sales/task-show', 'layout', [
            'task' => $task,
            'users' => $allUsers,
            'assignees' => array_values(array_filter($allUsers, fn ($u) => in_array((int) $u['id'], $assigneeIds, true))),
            'comments' => $this->tasks->comments($id),
            'attachments' => $this->tasks->attachments($id),
            'can' => $this->tasks->capabilities(CurrentUser::user()),
            'errors' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->tasks->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', t('tasks.flash.added'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add task.');
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
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update task.');
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function toggleComplete(Request $request): Response
    {
        $this->tasks->toggleComplete(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->redirect($this->backTo($request));
    }

    public function delete(Request $request): Response
    {
        $this->tasks->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('tasks.flash.deleted'));

        return $this->redirect($this->backTo($request));
    }

    public function addComment(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->addComment(CurrentUser::user(), $id, (string) $request->input('body', ''));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add comment.');
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function deleteComment(Request $request): Response
    {
        $this->tasks->deleteComment(CurrentUser::user(), (int) $request->attribute('id'), (int) $request->attribute('commentId'));

        return $this->redirect('/tasks/' . (int) $request->attribute('id'));
    }

    public function uploadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $file = $request->file('file');

        if ($file === null) {
            Session::setFlash('error', 'Choose a file first.');

            return $this->redirect('/tasks/' . $id);
        }

        try {
            $this->tasks->addAttachment(CurrentUser::user(), $id, $file);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not upload file.');
        }

        return $this->redirect('/tasks/' . $id);
    }

    public function deleteAttachment(Request $request): Response
    {
        $this->tasks->deleteAttachment(CurrentUser::user(), (int) $request->attribute('id'), (int) $request->attribute('attachmentId'));

        return $this->redirect('/tasks/' . (int) $request->attribute('id'));
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

    private function backTo(Request $request): string
    {
        $returnTo = (string) $request->input('return_to', '');

        return $returnTo !== '' ? $returnTo : '/tasks';
    }
}
