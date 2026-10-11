<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\RoleService;
use App\Application\Services\UserManagementService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

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

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'role_id' => (string) $request->query('role_id', ''),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : '',
        ];
        $users = array_filter($this->users->listForOrganization($actingUser), fn ($u) =>
            ($filters['q'] === '' || mb_stripos($u['first_name'] . ' ' . $u['last_name'] . ' ' . $u['email'], $filters['q']) !== false)
            && ($filters['role_id'] === '' || (string) $u['role_id'] === $filters['role_id'])
            && ($filters['status'] === '' || $u['status'] === $filters['status']));
        [$users, $pagination] = Pagination::slice($users, $request);

        return $this->viewWithLayout('org/users', 'layout', [
            'users' => $users,
            'pagination' => $pagination,
            'filters' => $filters,
            'roles' => $this->roles->listForOrganization($actingUser),
            'grants' => $this->users->permissionGrantsOverview($actingUser, $users),
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
            $users = $this->users->listForOrganization($actingUser);

            return $this->viewWithLayout('org/users', 'layout', [
                'users' => $users,
                'roles' => $this->roles->listForOrganization($actingUser),
                'grants' => $this->users->permissionGrantsOverview($actingUser, $users),
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

    public function updatePermissionGrant(Request $request): Response
    {
        $this->users->setPermissionGrant(
            CurrentUser::user(),
            (int) $request->attribute('id'),
            (string) $request->input('permission', ''),
            $request->input('granted') === '1'
        );

        Session::setFlash('success', t('org.access_updated'));

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
