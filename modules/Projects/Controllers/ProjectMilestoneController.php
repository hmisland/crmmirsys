<?php

declare(strict_types=1);

namespace Modules\Projects\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Projects\Services\ProjectMilestoneService;

use function App\Infrastructure\Theme\t;

/**
 * Milestones on the project page (add inline, edit on its own small form,
 * delete with confirm). Thin, like ProjectController: every rule is
 * ProjectMilestoneService's. Routes: ModuleMiddleware('projects') +
 * RbacMiddleware(projects.edit) (ProjectsModule).
 */
final class ProjectMilestoneController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ProjectMilestoneService $milestones,
    ) {
        parent::__construct($templates);
    }

    public function create(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');

        try {
            $this->milestones->create(CurrentUser::user(), $projectId, $request->all());
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'projects.milestones.error.name_required')));

            return $this->redirect('/projects/' . $projectId);
        }
        Session::setFlash('success', t('projects.milestones.flash.created'));

        return $this->redirect('/projects/' . $projectId);
    }

    public function edit(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');
        $milestone = $this->milestones->find(CurrentUser::user(), $projectId, (int) $request->attribute('milestoneId'));

        return $this->form($projectId, $milestone, [], []);
    }

    public function update(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');
        $milestoneId = (int) $request->attribute('milestoneId');

        try {
            $this->milestones->update(CurrentUser::user(), $projectId, $milestoneId, $request->all());
        } catch (ValidationException $e) {
            // The project itself is closed: nothing to correct in the form.
            if (($e->errors['status'][0] ?? null) === 'projects.error.not_editable') {
                Session::setFlash('error', t('projects.error.not_editable'));

                return $this->redirect('/projects/' . $projectId);
            }
            $milestone = $this->milestones->find(CurrentUser::user(), $projectId, $milestoneId);
            $errors = array_map(fn (array $messages) => array_map(fn (string $key) => t($key), $messages), $e->errors);

            return $this->form($projectId, $milestone, $errors, $request->all(), 422);
        }
        Session::setFlash('success', t('projects.milestones.flash.updated'));

        return $this->redirect('/projects/' . $projectId);
    }

    public function delete(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');

        try {
            $this->milestones->delete(CurrentUser::user(), $projectId, (int) $request->attribute('milestoneId'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'projects.error.not_editable')));

            return $this->redirect('/projects/' . $projectId);
        }
        Session::setFlash('success', t('projects.milestones.flash.deleted'));

        return $this->redirect('/projects/' . $projectId);
    }

    private function form(int $projectId, array $milestone, array $errors, array $old, int $status = 200): Response
    {
        return $this->viewWithLayout('projects/milestone-form', 'layout', [
            'projectId' => $projectId,
            'milestone' => $milestone,
            'errors' => $errors,
            'old' => $old,
            'statuses' => ProjectMilestoneService::STATUSES,
        ], $status);
    }
}
