<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization;

use App\Infrastructure\Repositories\RolePermissionRepository;
use App\Infrastructure\Repositories\UserPermissionGrantRepository;

/**
 * Per Phase 0 §10–11/§17: the ONLY place scope logic is evaluated —
 * no `if ($role === 'admin')` anywhere else in the codebase.
 * RbacMiddleware and any Service doing a record-level check both call
 * this, never re-implement it.
 */
final class AuthorizationEngine
{
    /** @var array<string, bool> "userId|permissionKey" => granted, per request */
    private array $grantCache = [];

    public function __construct(
        private readonly RolePermissionRepository $rolePermissions,
        private readonly ?UserPermissionGrantRepository $grants = null,
    ) {
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
        $row = $roleId !== null ? $this->rolePermissions->findForRole((int) $roleId, $permissionKey) : null;

        if ($row !== null) {
            return Scope::from($row['scope']);
        }

        // A role without the permission can still be topped up per
        // user — only for the short Permission::USER_GRANTABLE list,
        // and always at organization scope.
        return $this->hasUserGrant($user, $permissionKey) ? Scope::ORGANIZATION : null;
    }

    private function hasUserGrant(array $user, string $permissionKey): bool
    {
        if ($this->grants === null || !in_array($permissionKey, Permission::USER_GRANTABLE, true)
            || empty($user['id']) || empty($user['organization_id'])) {
            return false;
        }

        $cacheKey = $user['id'] . '|' . $permissionKey;

        if (!isset($this->grantCache[$cacheKey])) {
            try {
                $this->grantCache[$cacheKey] = $this->grants->has((int) $user['organization_id'], (int) $user['id'], $permissionKey);
            } catch (\PDOException) {
                // Table not migrated yet on this install — no grants.
                $this->grantCache[$cacheKey] = false;
            }
        }

        return $this->grantCache[$cacheKey];
    }

    /** Drops the per-request grant cache after a grant/revoke. */
    public function forgetGrants(): void
    {
        $this->grantCache = [];
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
