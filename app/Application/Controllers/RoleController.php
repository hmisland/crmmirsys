<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\RoleService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class RoleController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly RoleService $roles)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $roles = $this->roles->listForOrganization($actingUser);

        $editingRole = null;
        $editingPermissions = [];
        $editId = $request->query('edit');

        if ($editId !== null && $editId !== '') {
            $editingRole = $this->roles->find($actingUser, (int) $editId);
            $editingPermissions = $this->roles->permissionsFor((int) $editId);
        }

        return $this->viewWithLayout('org/roles', 'layout', [
            'roles' => $roles,
            'allPermissions' => Permission::all(),
            'editingRole' => $editingRole,
            'editingPermissions' => $editingPermissions,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        try {
            $this->roles->create($actingUser, (string) $request->input('name', ''), $this->permissionsFromInput($request));
        } catch (ValidationException $e) {
            return $this->viewWithLayout('org/roles', 'layout', [
                'roles' => $this->roles->listForOrganization($actingUser),
                'allPermissions' => Permission::all(),
                'editingRole' => null,
                'editingPermissions' => [],
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Role created.');

        return $this->redirect('/org/roles');
    }

    public function update(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $roleId = (int) $request->attribute('id');

        try {
            $this->roles->update($actingUser, $roleId, (string) $request->input('name', ''), $this->permissionsFromInput($request));
        } catch (ValidationException $e) {
            return $this->viewWithLayout('org/roles', 'layout', [
                'roles' => $this->roles->listForOrganization($actingUser),
                'allPermissions' => Permission::all(),
                'editingRole' => $this->roles->find($actingUser, $roleId),
                'editingPermissions' => $this->permissionsFromInput($request),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Role updated.');

        return $this->redirect('/org/roles');
    }

    public function delete(Request $request): Response
    {
        $this->roles->delete(CurrentUser::user(), (int) $request->attribute('id'));

        Session::setFlash('success', 'Role deleted.');

        return $this->redirect('/org/roles');
    }

    /**
     * Form submits permissions as permissions[<key>]=<scope> for each
     * checked permission (the template only includes a key when its
     * checkbox is checked, via a per-permission scope <select>).
     */
    private function permissionsFromInput(Request $request): array
    {
        $raw = $request->input('permissions', []);

        return is_array($raw) ? $raw : [];
    }
}
