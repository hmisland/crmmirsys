<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\RolePermissionRepository;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Validation\Validator;

final class RoleService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly RoleRepository $roles,
        private readonly RolePermissionRepository $rolePermissions,
        private readonly AuthorizationEngine $authorization,
    ) {
        parent::__construct($logger, $events);
    }

    public function listForOrganization(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::ROLES_VIEW);

        return $this->roles->findAvailableForOrganization((int) $actingUser['organization_id']);
    }

    public function find(array $actingUser, int $roleId): array
    {
        $this->authorize($actingUser, Permission::ROLES_VIEW);

        return $this->requireVisibleRole($actingUser, $roleId);
    }

    public function permissionsFor(int $roleId): array
    {
        $result = [];

        foreach ($this->rolePermissions->allForRole($roleId) as $row) {
            $result[$row['permission_key']] = $row['scope'];
        }

        return $result;
    }

    /**
     * Always creates an organization-owned role (organization_id set)
     * — an org admin can never create a new system template, only
     * their own custom role, optionally starting from a copy of a
     * system template's permissions (per Phase 0 §15: "clone/customize
     * system role templates").
     *
     * @param array<string, string> $permissions permission_key => scope
     */
    public function create(array $actingUser, string $name, array $permissions): array
    {
        $this->authorize($actingUser, Permission::ROLES_CREATE);

        $validator = Validator::make(['name' => $name], ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->assertValidPermissions($permissions);

        $roleId = $this->roles->create([
            'organization_id' => (int) $actingUser['organization_id'],
            'key' => $this->slugify($name) . '-' . bin2hex(random_bytes(4)),
            'name' => trim($name),
            'is_system' => 0,
        ]);

        if ($permissions !== []) {
            $this->rolePermissions->replaceForRole($roleId, $permissions);
        }

        return $this->roles->findById($roleId);
    }

    /**
     * @param array<string, string> $permissions permission_key => scope
     */
    public function update(array $actingUser, int $roleId, string $name, array $permissions): array
    {
        $this->authorize($actingUser, Permission::ROLES_EDIT);

        $role = $this->requireVisibleRole($actingUser, $roleId);

        if ((bool) $role['is_system']) {
            throw new AuthorizationException('System role templates cannot be edited directly — create a custom role instead.');
        }

        if ((int) $role['organization_id'] !== (int) $actingUser['organization_id'] && empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException('That role does not belong to your organization.');
        }

        $validator = Validator::make(['name' => $name], ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->assertValidPermissions($permissions);

        $this->roles->updateRole($roleId, ['name' => trim($name)]);
        $this->rolePermissions->replaceForRole($roleId, $permissions);

        return $this->roles->findById($roleId);
    }

    public function delete(array $actingUser, int $roleId): void
    {
        $this->authorize($actingUser, Permission::ROLES_DELETE);

        $role = $this->requireVisibleRole($actingUser, $roleId);

        if ((bool) $role['is_system']) {
            throw new AuthorizationException('System role templates cannot be deleted.');
        }

        $this->roles->deleteRole($roleId);
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requireVisibleRole(array $actingUser, int $roleId): array
    {
        $role = $this->roles->findById($roleId);

        if ($role === null) {
            throw new NotFoundException('Role not found.');
        }

        $visible = $role['organization_id'] === null
            || !empty($actingUser['is_super_admin'])
            || (int) $role['organization_id'] === (int) $actingUser['organization_id'];

        if (!$visible) {
            throw new NotFoundException('Role not found.');
        }

        return $role;
    }

    /**
     * @param array<string, string> $permissions
     */
    private function assertValidPermissions(array $permissions): void
    {
        $validScopes = array_map(fn ($case) => $case->value, Scope::cases());

        foreach ($permissions as $key => $scope) {
            if (!Permission::exists($key)) {
                throw new ValidationException(['permissions' => ["Unknown permission key: {$key}"]]);
            }

            if (!in_array($scope, $validScopes, true)) {
                throw new ValidationException(['permissions' => ["Invalid scope \"{$scope}\" for {$key}"]]);
            }
        }
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

        return $slug !== '' ? $slug : 'role';
    }
}
