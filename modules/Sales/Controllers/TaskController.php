<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Services\TaskCalendar;
use Modules\Sales\Services\TaskLabelService;
use Modules\Sales\Services\TaskReminderService;
use Modules\Sales\Services\TaskService;
use Modules\Sales\Services\TaskStatusService;

use function App\Infrastructure\Theme\local_today;
use function App\Infrastructure\Theme\t;

final class TaskController extends BaseController
{
    /** At most this many tasks in one calendar period (the page says so when reached). */
    private const CALENDAR_LIMIT = 1000;

    /** Fields of the task edit form that can carry an error message. */
    private const EDIT_FIELDS = ['status_id', 'title', 'start_at', 'start_time', 'due_at', 'due_time', 'remind_at', 'remind_time', 'recur_type', 'recur_interval', 'recur_until', 'related', 'assigned_to_user_ids'];

    public function __construct(
        TemplateEngine $templates,
        private readonly TaskService $tasks,
        private readonly TaskLabelService $labelService,
        private readonly TaskReminderService $reminders,
    ) {
        parent::__construct($templates);
    }

    /** ?new=1 renders the "New task" dialog already open (the no-JavaScript path of the button). */
    public function myTasks(Request $request): Response
    {
        // From the calendar (T9): ?new=1&due_at=Y-m-d[&due_time=HH:MM] pre-fills the dialog's due date.
        $old = [];
        if ($request->query('new') === '1' && ($due = TaskCalendar::validDate($request->query('due_at'))) !== null) {
            $old['due_at'] = $due;
            $time = $request->query('due_time');
            if (is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time) === 1) {
                $old['due_time'] = $time;
            }
        }

        return $this->renderList($request->allQuery(), $request->query('new') === '1', $old);
    }

    /**
     * The task calendar (T9): month / week / day of the organization's local
     * calendar, with the list's scope tabs and filters (search, status,
     * label). The same criteria and row checks as the list (listCriteria(),
     * visibleRows()), limited to the visible period.
     */
    public function calendar(Request $request): Response
    {
        $user = CurrentUser::user();
        $fmt = self::formatter();
        $locale = $fmt->locale();
        $mode = in_array($request->query('mode'), TaskCalendar::VIEWS, true) ? (string) $request->query('mode') : 'month';
        $date = TaskCalendar::validDate($request->query('date')) ?? local_today();
        $scope = in_array($request->query('view'), ['created', 'all'], true) ? (string) $request->query('view') : 'mine';
        $search = is_string($request->query('q')) ? trim((string) $request->query('q')) : '';
        $digits = fn (string $key): ?int => is_string($request->query($key)) && ctype_digit((string) $request->query($key)) ? (int) $request->query($key) : null;

        $criteria = $this->tasks->listCriteria($user, $scope, null, $search, $digits('status_id'), $digits('label_id'));
        $period = TaskCalendar::period($mode, $date, $locale);
        $tasks = $this->tasks->calendarTasks($user, $criteria, $period['from'] . ' 00:00:00', $period['to'] . ' 00:00:00', self::CALENDAR_LIMIT);

        // Only the filters that applied (another organization's ids are dropped).
        $filters = array_filter([
            'view' => $scope !== 'mine' ? $scope : '',
            'q' => $search,
            'status_id' => isset($criteria['status_filter']) ? (string) $criteria['status_filter']['id'] : '',
            'label_id' => isset($criteria['label_id']) ? (string) $criteria['label_id'] : '',
        ], fn (string $v) => $v !== '');

        return $this->viewWithLayout('sales/task-calendar', 'layout', [
            'mode' => $mode,
            'date' => $date,
            'period' => $period,
            'days' => TaskCalendar::place($tasks, $period['from'], $period['to']),
            'weekdays' => TaskCalendar::weekOrder($locale),
            'truncated' => count($tasks) >= self::CALENDAR_LIMIT,
            'filters' => $filters,
            'search' => $search,
            'scope' => $scope,
            'canListAll' => $this->tasks->canListAll($user),
            'canCreate' => $this->tasks->capabilities($user)['create'],
            'taskStatuses' => $this->tasks->statusMap(),
            'taskLabels' => $this->tasks->labelsFor($tasks),
            'allLabels' => $this->labelService->all($user),
            'today' => local_today(),
            'fmt' => $fmt,
        ]);
    }

    /**
     * The task list: view, status filter, search (?q=) and database-side
     * pagination (?page= / ?per_page=) — the Contacts list's pattern
     * (Pagination::fromRequest() after a COUNT, then LIMIT/OFFSET). After a
     * failed create it is rendered again (422) with the dialog open, the
     * submitted values ($old) and the field errors.
     */
    private function renderList(array $query, bool $openCreate, array $old = [], array $errors = [], int $httpStatus = 200): Response
    {
        $user = CurrentUser::user();
        $view = in_array($query['view'] ?? null, TaskService::LIST_VIEWS, true) ? (string) $query['view'] : 'mine';
        $status = in_array($query['status'] ?? null, TaskService::STATUSES, true) ? (string) $query['status'] : '';
        $search = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        // A custom status (T8); the older ?status= (legacy values) keeps working.
        $statusId = is_string($query['status_id'] ?? null) && ctype_digit($query['status_id']) ? (int) $query['status_id'] : null;

        // The list's own query (never ?new=1), in a fixed order: page links,
        // the filter form and every action's return_to keep it.
        $listQuery = array_filter([
            'view' => $view !== 'mine' ? $view : '',
            'status' => $status,
            'q' => $search,
            'status_id' => $statusId !== null ? (string) $statusId : '',
            'page' => is_scalar($query['page'] ?? null) ? (string) $query['page'] : '',
            'per_page' => is_scalar($query['per_page'] ?? null) ? (string) $query['per_page'] : '',
        ], fn (string $v) => $v !== '');

        $criteria = $this->tasks->listCriteria($user, $view, $status !== '' ? $status : null, $search, $statusId);
        if (!isset($criteria['status_filter'])) {
            unset($listQuery['status_id']); // not a status of this organization
        }
        $pagination = Pagination::fromRequest(new Request('GET', '/tasks', $listQuery, [], [], []), $this->tasks->countList($criteria));
        $tasks = $this->tasks->listPage($user, $criteria, $pagination->perPage, $pagination->offset());
        // Keep only the effective paging values (clamped page, a valid page size).
        unset($listQuery['page'], $listQuery['per_page']);
        if ($pagination->page > 1) {
            $listQuery['page'] = (string) $pagination->page;
        }
        if ($pagination->perPage !== Pagination::DEFAULT_PER_PAGE) {
            $listQuery['per_page'] = (string) $pagination->perPage;
        }

        return $this->viewWithLayout('sales/tasks', 'layout', [
            'tasks' => $tasks,
            'pagination' => $pagination,
            'listQuery' => $listQuery,
            'search' => $search,
            'users' => $this->tasks->assignableUsers((int) $user['organization_id']),
            'view' => $view,
            'canListAll' => $this->tasks->canListAll($user),
            'archived' => $view === 'archived',
            'statusFilter' => $status,
            'statusIdFilter' => isset($criteria['status_filter']) ? (int) $criteria['status_filter']['id'] : null,
            'taskStatuses' => $this->tasks->statusMap(),
            'createStatuses' => $this->tasks->createStatusOptions(),
            'canManageStatuses' => $this->tasks->statuses()->canManage($user),
            'can' => $this->tasks->capabilities($user),
            'taskActions' => $this->tasks->actionsFor($user, $tasks),
            'taskProgress' => $this->tasks->checklistProgressFor($tasks),
            'taskLabels' => $this->tasks->labelsFor($tasks),
            'taskRelated' => $this->tasks->relatedFor($user, $tasks),
            'relatedOptions' => $this->tasks->capabilities($user)['create'] ? $this->tasks->relatedOptions($user) : [],
            'canManageLabels' => $this->labelService->canManage($user),
            'reminderUnread' => $this->reminders->unreadCount($user),
            'openCreate' => $openCreate || $errors !== [],
            'old' => $old,
            'errors' => $errors,
            'fmt' => self::formatter(),
        ], $httpStatus);
    }

    /** Dates and digits in the user's language (Jalali in Persian), as Appointments does. */
    public static function formatter(): LocaleFormatter
    {
        $translator = Locale::get();

        return new LocaleFormatter($translator !== null ? $translator->locale() : 'en');
    }

    public function changeStatus(Request $request): Response
    {
        $status = (string) $request->input('status', '');
        $statusId = $request->input('status_id');

        try {
            if (is_string($statusId) && $statusId !== '') {
                // One of the organization's custom statuses (T8).
                $task = $this->tasks->changeStatusTo(CurrentUser::user(), (int) $request->attribute('id'), ctype_digit($statusId) ? (int) $statusId : 0);
                $shown = TaskStatusService::effective($task, $this->tasks->statusMap());
                Session::setFlash('success', t('tasks.flash.status_changed', ['status' => $shown !== null ? $shown['label'] : t('tasks.status.' . $task['status'])]));

                return $this->redirect($this->backTo($request));
            }
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
        return $this->renderShow((int) $request->attribute('id'));
    }

    /**
     * One task. Opening it reads this user's reminders for it. After a failed
     * edit it is rendered again (422) with the submitted values ($old) and
     * the field errors in the edit form.
     */
    private function renderShow(int $id, array $old = [], array $errors = [], int $httpStatus = 200): Response
    {
        $user = CurrentUser::user();
        $task = $this->tasks->find($user, $id);
        if ($httpStatus === 200) {
            $this->reminders->markTaskRead($user, $id);
        }
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
            'checklist' => $this->tasks->checklist($id),
            'checklistProgress' => $this->tasks->checklistProgress($id),
            'labels' => $this->tasks->labelsFor([$task])[$id] ?? [],
            'allLabels' => $this->labelService->all($user),
            'related' => $this->tasks->relatedFor($user, [$task])[$id] ?? null,
            'relatedOptions' => $this->tasks->actionsFor($user, [$task])[$id]['edit'] ? $this->tasks->relatedOptions($user) : [],
            'canManageLabels' => $this->labelService->canManage($user),
            'series' => $this->tasks->seriesLinks($user, $task),
            'taskStatuses' => $this->tasks->statusMap(),
            'old' => $old,
            'errors' => $errors,
            'fmt' => self::formatter(),
        ], $httpStatus);
    }

    public function create(Request $request): Response
    {
        try {
            $this->tasks->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', t('tasks.flash.added'));
        } catch (ValidationException $e) {
            // From the task list: show the list again with the dialog open and
            // nothing lost. Elsewhere (the deal page's quick add): as before.
            $back = $this->backTo($request);
            if (preg_match('#^/tasks(\?|$)#', $back) === 1) {
                parse_str((string) parse_url($back, PHP_URL_QUERY), $query);

                return $this->renderList($query, true, $request->all(), $e->errors, 422);
            }
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
            // A field of the edit form: show the form again, nothing lost.
            if (array_intersect_key($e->errors, array_flip(self::EDIT_FIELDS)) !== []) {
                return $this->renderShow($id, $request->all(), $e->errors, 422);
            }
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

    // --- Labels (T4) -------------------------------------------------------------

    /** The organization's label management page (tasks.edit at organization scope). */
    public function labels(Request $request): Response
    {
        $user = CurrentUser::user();
        if (!$this->labelService->canManage($user)) {
            throw new \App\Core\Exceptions\AuthorizationException();
        }

        return $this->viewWithLayout('sales/task-labels', 'layout', [
            'labels' => $this->labelService->all($user),
            'colors' => TaskLabelService::COLORS,
        ]);
    }

    public function createLabel(Request $request): Response
    {
        return $this->labelAction(fn () => $this->labelService->create(CurrentUser::user(), $request->all()), 'tasks.flash.label_saved');
    }

    public function updateLabel(Request $request): Response
    {
        return $this->labelAction(fn () => $this->labelService->update(CurrentUser::user(), (int) $request->attribute('labelId'), $request->all()), 'tasks.flash.label_saved');
    }

    public function deleteLabel(Request $request): Response
    {
        return $this->labelAction(fn () => $this->labelService->delete(CurrentUser::user(), (int) $request->attribute('labelId')), 'tasks.flash.label_deleted');
    }

    /** Puts exactly the checked labels on the task (none checked = no labels). */
    public function setLabels(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tasks->setLabels(CurrentUser::user(), $id, $request->input('label_ids', []));
            Session::setFlash('success', t('tasks.flash.labels_saved'));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/' . $id . '#labels');
    }

    private function labelAction(callable $change, string $successKey): Response
    {
        try {
            $change();
            Session::setFlash('success', t($successKey));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/labels');
    }

    // --- Checklist (T3): plain form posts, or fetch() with Accept: application/json ---

    public function addChecklistItem(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->checklistResponse($request, $id, fn () => $this->tasks->addChecklistItem(CurrentUser::user(), $id, $request->input('title')));
    }

    public function renameChecklistItem(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->checklistResponse($request, $id, fn () => $this->tasks->renameChecklistItem(CurrentUser::user(), $id, (int) $request->attribute('itemId'), $request->input('title')));
    }

    public function setChecklistItemDone(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->checklistResponse($request, $id, fn () => $this->tasks->setChecklistItemDone(CurrentUser::user(), $id, (int) $request->attribute('itemId'), $request->input('done') === '1'));
    }

    public function deleteChecklistItem(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->checklistResponse($request, $id, function () use ($request, $id) {
            $this->tasks->deleteChecklistItem(CurrentUser::user(), $id, (int) $request->attribute('itemId'));

            return null;
        });
    }

    /**
     * Runs one checklist change. JSON callers get the item's new HTML and the
     * progress (or a 422 in the ErrorHandler's JSON shape); a plain form post
     * goes back to the checklist, with a flash on a validation error.
     * Authorization / not-found errors propagate as for every task route.
     */
    private function checklistResponse(Request $request, int $taskId, callable $change): Response
    {
        try {
            $item = $change();
        } catch (ValidationException $e) {
            if ($request->isJson()) {
                return Response::json(['errors' => [[
                    'status' => '422',
                    'code' => 'validation_failed',
                    'title' => $this->firstError($e),
                    'meta' => ['fields' => $e->errors],
                ]]], 422);
            }
            Session::setFlash('error', $this->firstError($e));

            return $this->redirect('/tasks/' . $taskId . '#checklist');
        }

        if (!$request->isJson()) {
            return $this->redirect('/tasks/' . $taskId . '#checklist');
        }

        $progress = $this->tasks->checklistProgress($taskId);

        return Response::json(['data' => [
            'html' => $item === null ? null : $this->templates->render('sales/_checklist_item', ['taskId' => $taskId, 'item' => $item, 'canEdit' => true]),
            'progress' => $progress,
            'progress_label' => self::formatter()->digits(t('tasks.checklist_progress', $progress)),
        ]]);
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

    // --- Custom statuses (T8): organization.edit only -------------------------

    public function statusesPage(Request $request): Response
    {
        $service = $this->tasks->statuses();
        if (!$service->canManage(CurrentUser::user())) {
            throw new \App\Core\Exceptions\AuthorizationException();
        }
        $rows = $service->all();

        return $this->viewWithLayout('sales/task-statuses', 'layout', [
            'statuses' => array_map(fn (array $s) => $s + ['usage' => $service->usage($s), 'replacements' => $service->replacementsFor($s)], $rows),
            'colors' => TaskStatusService::COLORS,
            'categories' => TaskStatusService::CATEGORIES,
        ]);
    }

    public function createStatus(Request $request): Response
    {
        return $this->statusAction(fn () => $this->tasks->statuses()->create(CurrentUser::user(), $request->all()), 'tasks.flash.status_saved');
    }

    public function updateStatus(Request $request): Response
    {
        return $this->statusAction(fn () => $this->tasks->statuses()->update(CurrentUser::user(), (int) $request->attribute('statusId'), $request->all()), 'tasks.flash.status_saved');
    }

    public function moveStatus(Request $request): Response
    {
        return $this->statusAction(fn () => $this->tasks->statuses()->move(CurrentUser::user(), (int) $request->attribute('statusId'), (string) $request->input('direction', '')), 'tasks.flash.status_saved');
    }

    public function deleteStatus(Request $request): Response
    {
        return $this->statusAction(fn () => $this->tasks->statuses()->delete(CurrentUser::user(), (int) $request->attribute('statusId'), $request->input('replacement_id')), 'tasks.flash.status_deleted');
    }

    private function statusAction(callable $change, string $successKey): Response
    {
        try {
            $change();
            Session::setFlash('success', t($successKey));
        } catch (ValidationException $e) {
            Session::setFlash('error', $this->firstError($e));
        }

        return $this->redirect('/tasks/statuses');
    }

    /** The user's reminder notifications (only tasks they may still see). */
    public function reminders(Request $request): Response
    {
        return $this->viewWithLayout('sales/task-reminders', 'layout', [
            'items' => $this->reminders->inbox(CurrentUser::user()),
            'fmt' => self::formatter(),
        ]);
    }

    public function readReminder(Request $request): Response
    {
        $this->reminders->markRead(CurrentUser::user(), (int) $request->attribute('notificationId'));

        return $this->redirect('/tasks/reminders');
    }

    public function readAllReminders(Request $request): Response
    {
        $this->reminders->markAllRead(CurrentUser::user());
        Session::setFlash('success', t('tasks.flash.reminders_read'));

        return $this->redirect('/tasks/reminders');
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
        // The query part may carry the list's url-encoded search (%XX, +, -, .).
        return preg_match('#^/(tasks(/\d+)?(\?[A-Za-z0-9_=&%+.\-]*)?|deals/\d+)$#D', $path) === 1;
    }
}
