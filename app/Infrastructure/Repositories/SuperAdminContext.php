<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Per Phase 0 §12–13: Super Admin bypasses tenant isolation only
 * through this EXPLICIT, request-scoped flag — never a silently
 * omitted WHERE clause. Every TenantScopedRepository call made while
 * this is active must still be traceable; hooking this into
 * AuditLogger is a Phase 3 task (once RBAC establishes who is
 * actually authorized to be a Super Admin) — the flag exists now so
 * TenantScopedRepository has a defined bypass path from day one
 * instead of that being bolted on later.
 */
final class SuperAdminContext
{
    private static bool $active = false;

    public static function enable(): void
    {
        // TODO (Phase 3): write an audit_log entry here once AuditLogger
        // and an authenticated Super Admin user both exist. Enabling
        // this without an audit trail is a Phase-1-only limitation.
        self::$active = true;
    }

    public static function disable(): void
    {
        self::$active = false;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Runs $callback with the bypass active, guaranteed to be turned
     * back off afterwards even if $callback throws.
     */
    public static function run(callable $callback): mixed
    {
        self::enable();

        try {
            return $callback();
        } finally {
            self::disable();
        }
    }
}
