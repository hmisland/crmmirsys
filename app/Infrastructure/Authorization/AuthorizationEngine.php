<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization;

use App\Infrastructure\Repositories\RolePermissionRepository;

/**
 * Per Phase 0 §10–11/§17: the ONLY place scope logic is evaluated —
 * no `if ($role === 'admin')` anywhere else in the codebase.
 * RbacMiddleware and any Service doing a record-level check both call
 * this, never re-implement it.
 */
final class AuthorizationEngine
{
    public function __construct(private readonly RolePermissionRepository $rolePermissions)
    {
    }

    /**
     * The scope a user holds for a permission, or null if they don't
     * have it at all. Useful on its own for list-level queries (e.g.
     * "if scope is OWN, add WHERE owner_id = :userId to the query"),
     * not just for a yes/no check.
     */
    public function scopeFor(array $user, string $permissionKey): ?Scope
    {
        if (!empty($user['is_super_admin'])) {
            return Scope::ALL;
        }

        $roleId = $user['role_id'] ?? null;

        if ($roleId === null) {
            return null;
        }

        $row = $this->rolePermissions->findForRole((int) $roleId, $permissionKey);

        return $row === null ? null : Scope::from($row['scope']);
    }

    /**
     * @param array|null $resource The specific record being acted on
     *   (needs an 'owner_id'/'user_id', 'team_id', or 'department_id'
     *   key depending on the scope being checked). Pass null for a
     *   blanket/list-level check with no single record — OWN/TEAM/
     *   DEPARTMENT then trivially pass, on the assumption the caller
     *   will filter the list itself using scopeFor().
     */
    public function can(array $user, string $permissionKey, ?array $resource = null): bool
    {
        $scope = $this->scopeFor($user, $permissionKey);

        if ($scope === null) {
            return false;
        }

        // Tenant isolation (TenantScopedRepository) already confines
        // any query to the caller's own organization, so ALL and
        // ORGANIZATION are equivalent from here — ALL only matters
        // for the Super Admin cross-org bypass, which is a repository-
        // level concern (SuperAdminContext), not this check's job.
        if ($scope === Scope::ALL || $scope === Scope::ORGANIZATION) {
            return true;
        }

        if ($resource === null) {
            return true;
        }

        return match ($scope) {
            Scope::OWN => $user['id'] === ($resource['owner_id'] ?? $resource['user_id'] ?? null),
            Scope::TEAM => $user['team_id'] !== null && $user['team_id'] === ($resource['team_id'] ?? null),
            Scope::DEPARTMENT => $user['department_id'] !== null && $user['department_id'] === ($resource['department_id'] ?? null),
            default => false,
        };
    }
}
