<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Per Phase 0 §12–13: any Repository dealing with organization-owned
 * data MUST extend this instead of BaseRepository directly. A
 * developer cannot forget the `organization_id` filter because there
 * is no query path here that omits it — short of explicitly wrapping
 * the call in SuperAdminContext::run(), which is deliberately
 * conspicuous in a code review/diff.
 *
 * Concrete module repositories (e.g. ContactRepository in Phase 5)
 * call these instead of BaseRepository's raw query()/findMany():
 *
 *   $this->tenantFindMany('SELECT * FROM contacts WHERE status = :status', ['status' => 'active']);
 *
 * which is automatically rewritten to filter by the resolved
 * OrganizationContext::id() (or skip the filter entirely, audibly,
 * under SuperAdminContext).
 */
abstract class TenantScopedRepository extends BaseRepository
{
    /**
     * The organization-owning table this repository is scoped to,
     * e.g. "contacts". Set by each concrete module repository.
     */
    abstract protected function table(): string;

    protected function tenantFindMany(string $sql, array $params = []): array
    {
        [$sql, $params] = $this->scopeQuery($sql, $params);

        return $this->findMany($sql, $params);
    }

    protected function tenantFindOne(string $sql, array $params = []): ?array
    {
        [$sql, $params] = $this->scopeQuery($sql, $params);

        return $this->findOne($sql, $params);
    }

    protected function tenantInsertGetId(array $columns): int
    {
        if (!SuperAdminContext::isActive()) {
            $columns['organization_id'] = OrganizationContext::id();
        }

        return $this->insertGetId($this->table(), $columns);
    }

    protected function tenantUpdate(array $columns, string $whereSql, array $whereParams): int
    {
        [$whereSql, $whereParams] = $this->scopeWhere($whereSql, $whereParams);

        return $this->update($this->table(), $columns, $whereSql, $whereParams);
    }

    protected function tenantDelete(string $whereSql, array $whereParams): int
    {
        [$whereSql, $whereParams] = $this->scopeWhere($whereSql, $whereParams);

        return $this->delete($this->table(), $whereSql, $whereParams);
    }

    /**
     * Injects "organization_id = :__org_id AND (...)" into a raw SELECT.
     * Expects $sql to already contain a WHERE clause; callers pass the
     * full statement (kept explicit/simple rather than a query builder
     * for Phase 1 — a proper QueryBuilder can replace this internally
     * later without changing any call site's method names).
     */
    private function scopeQuery(string $sql, array $params): array
    {
        if (SuperAdminContext::isActive()) {
            return [$sql, $params];
        }

        $params['__org_id'] = OrganizationContext::id();

        if (stripos($sql, 'WHERE') !== false) {
            $sql = preg_replace('/WHERE/i', 'WHERE organization_id = :__org_id AND', $sql, 1);
        } else {
            $sql .= ' WHERE organization_id = :__org_id';
        }

        return [$sql, $params];
    }

    private function scopeWhere(string $whereSql, array $whereParams): array
    {
        if (SuperAdminContext::isActive()) {
            return [$whereSql, $whereParams];
        }

        $whereParams['__org_id'] = OrganizationContext::id();

        return ["organization_id = :__org_id AND ({$whereSql})", $whereParams];
    }
}
