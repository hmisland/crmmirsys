<?php

declare(strict_types=1);

namespace Modules\Followups\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Followups\Services\FollowUpService;

final class FollowUpController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly FollowUpService $followUps,
        private readonly UserRepository $users,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $filters = [];
        if ($request->query('status') !== null && $request->query('status') !== '') {
            $filters['status'] = $request->query('status');
        }
        if ($request->query('overdue') === '1') {
            $filters['overdue_only'] = true;
        }

        return $this->viewWithLayout('followups/index', 'layout', [
            'followUps' => $this->followUps->list(CurrentUser::user(), $filters),
            'statusFilter' => (string) $request->query('status', ''),
            'overdueFilter' => $request->query('overdue') === '1',
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->form(null, [], []);
    }

    public function create(Request $request): Response
    {
        try {
            $followUp = $this->followUps->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all());
        }

        Session::setFlash('success', 'Follow-up created.');

        return $this->redirect('/followups/' . $followUp['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->viewWithLayout('followups/show', 'layout', [
            'followUp' => $this->followUps->find(CurrentUser::user(), $id),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->form($this->followUps->find(CurrentUser::user(), $id), [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->followUps->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->followUps->find(CurrentUser::user(), $id), $e->errors, $request->all());
        }

        Session::setFlash('success', 'Follow-up updated.');

        return $this->redirect('/followups/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->followUps->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Follow-up deleted.');

        return $this->redirect('/followups');
    }

    public function complete(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->followUps->complete(CurrentUser::user(), $id);
        Session::setFlash('success', 'Follow-up completed.');

        return $this->redirect('/followups/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->followUps->cancel(CurrentUser::user(), $id);
        Session::setFlash('success', 'Follow-up cancelled.');

        return $this->redirect('/followups/' . $id);
    }

    public function reschedule(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->followUps->reschedule(CurrentUser::user(), $id, (string) $request->input('due_at', ''));
            Session::setFlash('success', 'Follow-up rescheduled.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not reschedule.');
        }

        return $this->redirect('/followups/' . $id);
    }

    private function form(?array $followUp, array $errors, array $old): Response
    {
        return $this->viewWithLayout('followups/form', 'layout', [
            'followUp' => $followUp,
            'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
            'errors' => $errors,
            'old' => $old,
        ], $errors === [] ? 200 : 422);
    }
}
