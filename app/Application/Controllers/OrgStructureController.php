<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\OrgStructureService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class OrgStructureController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly OrgStructureService $structure)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        return $this->viewWithLayout('org/structure', 'layout', [
            'departments' => $this->structure->listDepartments($actingUser),
            'teams' => $this->structure->listTeams($actingUser),
            'errors' => [],
        ]);
    }

    public function createDepartment(Request $request): Response
    {
        $succeeded = $this->withErrorRedirect(function () use ($request) {
            $this->structure->createDepartment(CurrentUser::user(), (string) $request->input('name', ''));
        });

        if ($succeeded) {
            Session::setFlash('success', 'Department created.');
        }

        return $this->redirect('/org/structure');
    }

    public function deleteDepartment(Request $request): Response
    {
        $this->structure->deleteDepartment(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Department deleted.');

        return $this->redirect('/org/structure');
    }

    public function createTeam(Request $request): Response
    {
        $departmentId = $request->input('department_id');

        $succeeded = $this->withErrorRedirect(function () use ($request, $departmentId) {
            $this->structure->createTeam(
                CurrentUser::user(),
                (string) $request->input('name', ''),
                $departmentId !== '' && $departmentId !== null ? (int) $departmentId : null
            );
        });

        if ($succeeded) {
            Session::setFlash('success', 'Team created.');
        }

        return $this->redirect('/org/structure');
    }

    public function deleteTeam(Request $request): Response
    {
        $this->structure->deleteTeam(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Team deleted.');

        return $this->redirect('/org/structure');
    }

    /**
     * Departments/teams have no dedicated create/edit form page (kept
     * to one combined list+quick-add page for this phase) — a
     * validation failure here sets a flash error and redirects back
     * rather than re-rendering a form with inline field errors like
     * the auth pages do.
     */
    private function withErrorRedirect(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (ValidationException $e) {
            $first = array_values($e->errors)[0][0] ?? 'Invalid input.';
            Session::setFlash('error', $first);

            return false;
        }
    }
}
