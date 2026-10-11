<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\RolePermissionRepository;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\UserPermissionGrantRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\PasswordHasher;
use App\Infrastructure\Validation\Validator;

/**
 * Per Phase 0 §75: an Organization Admin manages their org's users
 * and role assignments. Every method here checks the acting user's
 * permission via AuthorizationEngine — this is the first place in the
 * app that actually enforces RBAC end to end, not just resolves it.
 */
final class UserManagementService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly AuthorizationEngine $authorization,
        private readonly UserPermissionGrantRepository $grants,
        private readonly RolePermissionRepository $rolePermissions,
    ) {
        parent::__construct($logger, $events);
    }

    public function listForOrganization(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::USERS_VIEW);

        return $this->users->findAllInOrganization((int) $actingUser['organization_id']);
    }

    /**
     * Creates a new user directly inside the acting admin's
     * organization (as opposed to AuthService::register(), which
     * creates a brand-new organization). No email verification is
     * required for an admin-created teammate — the admin creating the
     * account already vouches for the email being real; the account
     * is usable immediately with the temporary password the admin
     * sets, per the invite-style flow common to this kind of tool.
     */
    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::USERS_CREATE);

        $validator = Validator::make($input, [
            'email' => 'required|email',
            'password' => 'required|min:' . (int) Config::get('auth.password_min_length', 8),
            'first_name' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $email = strtolower(trim((string) $input['email']));

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException(['email' => ['An account with this email already exists.']]);
        }

        $roleId = $this->resolveAssignableRoleId($actingUser, $input['role_id'] ?? null);

        $userId = $this->users->create([
            'organization_id' => (int) $actingUser['organization_id'],
            'role_id' => $roleId,
            'email' => $email,
            'password_hash' => PasswordHasher::hash((string) $input['password']),
            'first_name' => trim((string) $input['first_name']),
            'last_name' => isset($input['last_name']) ? trim((string) $input['last_name']) : null,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->users->findById($userId);
    }

    public function updateRole(array $actingUser, int $targetUserId, int $roleId): array
    {
        $this->authorize($actingUser, Permission::ROLES_ASSIGN);

        $target = $this->requireSameOrganizationUser($actingUser, $targetUserId);
        $this->resolveAssignableRoleId($actingUser, $roleId); // throws if not assignable

        $this->users->updateRole($targetUserId, $roleId);

        return $this->users->findById($targetUserId);
    }

    public function setActive(array $actingUser, int $targetUserId, bool $active): array
    {
        $this->authorize($actingUser, $active ? Permission::USERS_ACTIVATE : Permission::USERS_DEACTIVATE);

        $this->requireSameOrganizationUser($actingUser, $targetUserId);

        if ($targetUserId === (int) $actingUser['id'] && !$active) {
            throw new ValidationException(['status' => ['You cannot deactivate your own account.']]);
        }

        $this->users->updateStatus($targetUserId, $active ? 'active' : 'inactive');

        return $this->users->findById($targetUserId);
    }

    /**
     * For the team members page: per user, per grantable permission —
     * 'role' (already comes with their role), 'granted' (individual
     * grant) or 'none'. Empty when the acting user can't manage grants.
     *
     * @return array<int, array<string, string>> user_id => [permission_key => state]
     */
    public function permissionGrantsOverview(array $actingUser, array $users): array
    {
        if (!$this->canManageGrants($actingUser)) {
            return [];
        }

        $grants = $this->grants->allInOrganization((int) $actingUser['organization_id']);
        $overview = [];

        foreach ($users as $user) {
            foreach (Permission::USER_GRANTABLE as $key) {
                $viaRole = $user['role_id'] !== null && $this->rolePermissions->findForRole((int) $user['role_id'], $key) !== null;
                $overview[(int) $user['id']][$key] = $viaRole
                    ? 'role'
                    : (in_array($key, $grants[(int) $user['id']] ?? [], true) ? 'granted' : 'none');
            }
        }

        return $overview;
    }

    public function canManageGrants(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, Permission::ROLES_ASSIGN);
    }

    /**
     * Grants or revokes one of Permission::USER_GRANTABLE for a single
     * teammate. The acting admin must hold the permission themselves —
     * nobody can hand out access they don't have.
     */
    public function setPermissionGrant(array $actingUser, int $targetUserId, string $permissionKey, bool $granted): void
    {
        $this->authorize($actingUser, Permission::ROLES_ASSIGN);

        if (!in_array($permissionKey, Permission::USER_GRANTABLE, true)) {
            throw new ValidationException(['permission' => ['This permission cannot be granted individually.']]);
        }

        $this->authorize($actingUser, $permissionKey);
        $target = $this->requireSameOrganizationUser($actingUser, $targetUserId);
        $organizationId = (int) $target['organization_id'];

        if ($granted) {
            $this->grants->grant($organizationId, $targetUserId, $permissionKey, (int) $actingUser['id']);
        } else {
            $this->grants->revoke($organizationId, $targetUserId, $permissionKey);
        }

        $this->authorization->forgetGrants();
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requireSameOrganizationUser(array $actingUser, int $targetUserId): array
    {
        $target = $this->users->findById($targetUserId);

        if ($target === null) {
            throw new NotFoundException('User not found.');
        }

        $sameOrg = !empty($actingUser['is_super_admin'])
            || ((int) ($target['organization_id'] ?? 0) === (int) $actingUser['organization_id']);

        if (!$sameOrg) {
            throw new AuthorizationException('That user is not in your organization.');
        }

        return $target;
    }

    /**
     * A role can only be assigned if it's a system template or
     * belongs to the acting user's own organization — never another
     * organization's custom role, even if its id is guessed.
     */
    private function resolveAssignableRoleId(array $actingUser, mixed $roleId): ?int
    {
        if ($roleId === null || $roleId === '') {
            return $this->defaultRoleId();
        }

        $role = $this->roles->findById((int) $roleId);

        if ($role === null) {
            throw new ValidationException(['role_id' => ['That role does not exist.']]);
        }

        $assignable = $role['organization_id'] === null
            || (int) $role['organization_id'] === (int) $actingUser['organization_id'];

        if (!$assignable) {
            throw new AuthorizationException('That role does not belong to your organization.');
        }

        return (int) $role['id'];
    }

    private function defaultRoleId(): ?int
    {
        foreach ($this->roles->findSystemTemplates() as $role) {
            if ($role['key'] === 'member') {
                return (int) $role['id'];
            }
        }

        return null;
    }
}
