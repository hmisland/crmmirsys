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
        $organizationId = (int) CurrentUser::user()['organization_id'];

        return $this->viewWithLayout('sales/tasks', 'layout', [
            'tasks' => $this->tasks->upcomingForUser((int) CurrentUser::id()),
            'users' => $this->users->findAllInOrganization($organizationId),
        ]);
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
            'errors' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->tasks->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', 'Task added.');
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
            Session::setFlash('success', 'Task updated.');
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
        Session::setFlash('success', 'Task deleted.');

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
        $this->tasks->deleteComment((int) $request->attribute('commentId'));

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
        $this->tasks->deleteAttachment((int) $request->attribute('attachmentId'));

        return $this->redirect('/tasks/' . (int) $request->attribute('id'));
    }

    public function downloadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $attachmentId = (int) $request->attribute('attachmentId');

        $match = null;
        foreach ($this->tasks->attachments($id) as $row) {
            if ((int) $row['id'] === $attachmentId) {
                $match = $row;
                break;
            }
        }

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
