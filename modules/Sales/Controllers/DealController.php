<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Sales\Services\DealService;
use Modules\Sales\Services\PipelineService;
use Modules\Sales\Services\TaskService;

final class DealController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly DealService $deals,
        private readonly PipelineService $pipelines,
        private readonly TaskService $tasks,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly UserRepository $users,
    ) {
        parent::__construct($templates);
    }

    /** The kanban board for the org's (currently: single) default pipeline. */
    public function board(Request $request): Response
    {
        $pipeline = $this->pipelines->ensureDefaultPipeline();
        $stages = $this->pipelines->stagesFor((int) $pipeline['id']);
        $actingUser = CurrentUser::user();

        return $this->viewWithLayout('sales/board', 'layout', [
            'pipeline' => $pipeline,
            'stages' => $stages,
            'dealsByStage' => $this->deals->board($actingUser, (int) $pipeline['id']),
            'totals' => $this->deals->totalsByStage($actingUser, (int) $pipeline['id']),
        ]);
    }

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), ['open', 'won', 'lost'], true) ? (string) $request->query('status') : '';
        [$deals, $pagination] = Pagination::slice($this->deals->list(CurrentUser::user(), [
            'search' => trim((string) $request->query('q', '')),
            'status' => $status,
        ]), $request);

        return $this->viewWithLayout('sales/index', 'layout', [
            'deals' => $deals,
            'pagination' => $pagination,
            'status' => $status,
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        $pipeline = $this->pipelines->ensureDefaultPipeline();

        return $this->viewWithLayout('sales/form', 'layout', [
            'deal' => null,
            'pipeline' => $pipeline,
            'stages' => $this->pipelines->stagesFor((int) $pipeline['id']),
            'contacts' => $this->contacts->search([]),
            'companies' => $this->companies->all(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        $pipeline = $this->pipelines->ensureDefaultPipeline();
        $input = $request->all();
        $input['pipeline_id'] = $input['pipeline_id'] ?? $pipeline['id'];

        try {
            $deal = $this->deals->create(CurrentUser::user(), $input);
        } catch (ValidationException $e) {
            return $this->viewWithLayout('sales/form', 'layout', [
                'deal' => null,
                'pipeline' => $pipeline,
                'stages' => $this->pipelines->stagesFor((int) $pipeline['id']),
                'contacts' => $this->contacts->search([]),
                'companies' => $this->companies->all(),
                'errors' => $e->errors,
                'old' => $input,
            ], 422);
        }

        return $this->redirect('/deals/' . $deal['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $deal = $this->deals->find(CurrentUser::user(), $id);
        $pipeline = $this->pipelines->find((int) $deal['pipeline_id']);

        $contact = $deal['contact_id'] !== null ? $this->contacts->findById((int) $deal['contact_id']) : null;
        $company = $deal['company_id'] !== null ? $this->companies->findById((int) $deal['company_id']) : null;
        $user = CurrentUser::user();
        // Only the deal's tasks this user may see (tasks.view and its scope).
        $tasks = $this->tasks->visibleForSubject($user, 'deal', $id);

        return $this->viewWithLayout('sales/show', 'layout', [
            'deal' => $deal,
            'pipeline' => $pipeline,
            'stages' => $this->pipelines->stagesFor((int) $deal['pipeline_id']),
            'contact' => $contact,
            'company' => $company,
            'tasks' => $tasks,
            'taskCan' => $this->tasks->capabilities($user),
            'taskActions' => $this->tasks->actionsFor($user, $tasks),
            'users' => $this->tasks->assignableUsers((int) $user['organization_id']),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $deal = $this->deals->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('sales/form', 'layout', [
            'deal' => $deal,
            'pipeline' => $this->pipelines->find((int) $deal['pipeline_id']),
            'stages' => $this->pipelines->stagesFor((int) $deal['pipeline_id']),
            'contacts' => $this->contacts->search([]),
            'companies' => $this->companies->all(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->deals->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $deal = $this->deals->find(CurrentUser::user(), $id);

            return $this->viewWithLayout('sales/form', 'layout', [
                'deal' => $deal,
                'pipeline' => $this->pipelines->find((int) $deal['pipeline_id']),
                'stages' => $this->pipelines->stagesFor((int) $deal['pipeline_id']),
                'contacts' => $this->contacts->search([]),
                'companies' => $this->companies->all(),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Deal updated.');

        return $this->redirect('/deals/' . $id);
    }

    public function changeStage(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->deals->changeStage(CurrentUser::user(), $id, (int) $request->input('stage_id'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not move deal.');
        }

        return $this->redirect($request->input('return_to', '/deals/' . $id));
    }

    public function delete(Request $request): Response
    {
        $this->deals->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Deal deleted.');

        return $this->redirect('/deals');
    }
}
