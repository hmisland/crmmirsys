<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Per Phase 2: set once per request by AuthMiddleware, after it has
 * re-fetched the user row fresh from the database (never trusting
 * stale session data for anything beyond the user id — see
 * Session::login()'s docblock).
 *
 * Deliberately mirrors App\Infrastructure\Repositories\OrganizationContext's
 * static-holder pattern, on purpose: TenantMiddleware (Phase 3) will
 * read CurrentUser::user()['organization_id'] and call
 * OrganizationContext::set() with it, so this is the seam Phase 3
 * plugs into rather than a new mechanism.
 */
final class CurrentUser
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    public static function set(array $user): void
    {
        self::$user = $user;
    }

    public static function isGuest(): bool
    {
        return self::$user === null;
    }

    public static function id(): ?int
    {
        return self::$user['id'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function reset(): void
    {
        self::$user = null;
    }
}
