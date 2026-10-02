<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\RoleService;
use App\Application\Services\UserManagementService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class UserManagementController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly UserManagementService $users,
        private readonly RoleService $roles,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        return $this->viewWithLayout('org/users', 'layout', [
            'users' => $this->users->listForOrganization($actingUser),
            'roles' => $this->roles->listForOrganization($actingUser),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        try {
            $this->users->create($actingUser, $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('org/users', 'layout', [
                'users' => $this->users->listForOrganization($actingUser),
                'roles' => $this->roles->listForOrganization($actingUser),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Teammate added.');

        return $this->redirect('/org/users');
    }

    public function updateRole(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $targetUserId = (int) $request->attribute('id');
        $roleId = (int) $request->input('role_id');

        $this->users->updateRole($actingUser, $targetUserId, $roleId);

        Session::setFlash('success', 'Role updated.');

        return $this->redirect('/org/users');
    }

    public function activate(Request $request): Response
    {
        $this->users->setActive(CurrentUser::user(), (int) $request->attribute('id'), true);

        Session::setFlash('success', 'User activated.');

        return $this->redirect('/org/users');
    }

    public function deactivate(Request $request): Response
    {
        $this->users->setActive(CurrentUser::user(), (int) $request->attribute('id'), false);

        Session::setFlash('success', 'User deactivated.');

        return $this->redirect('/org/users');
    }
}
