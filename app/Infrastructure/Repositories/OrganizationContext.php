<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Per Phase 0 §12–13: the single source of truth for "which
 * organization is this request operating as". TenantMiddleware
 * (Phase 3) is the only code that should ever call set() in normal
 * request handling — everything downstream (TenantScopedRepository)
 * only reads it.
 *
 * Request-scoped by design (reset() is called at the start of each
 * request by the front controller) so nothing leaks between requests
 * even under a persistent worker model in a future deployment target.
 */
final class OrganizationContext
{
    private static ?int $organizationId = null;

    public static function set(int $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    public static function has(): bool
    {
        return self::$organizationId !== null;
    }

    public static function id(): int
    {
        if (self::$organizationId === null) {
            throw new \RuntimeException(
                'No OrganizationContext is set. A TenantScopedRepository was used ' .
                'outside a request that resolved a tenant, and SuperAdminContext was not active.'
            );
        }

        return self::$organizationId;
    }

    public static function reset(): void
    {
        self::$organizationId = null;
    }

    /**
     * Runs $callback with the context temporarily pinned to
     * $organizationId, restoring whatever it was before afterwards
     * (even if $callback throws) — for the one legitimate case a
     * Super Admin needs to act "as" a specific organization without
     * actually belonging to it: managing that org's own module
     * entitlements (Phase 4/6 correction — see ModuleService).
     */
    public static function runAs(int $organizationId, callable $callback): mixed
    {
        $previous = self::$organizationId;
        self::$organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            self::$organizationId = $previous;
        }
    }
}
