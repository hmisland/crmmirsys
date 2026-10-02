<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Per Phase 0 §32: never store plain-text passwords. Wraps PHP's own
 * password_hash/password_verify rather than a custom scheme, and
 * prefers Argon2id where the extension is compiled in (not guaranteed
 * on every shared cPanel PHP build), falling back to bcrypt otherwise.
 */
final class PasswordHasher
{
    public static function hash(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        return password_hash($plain, $algo);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        return password_needs_rehash($hash, $algo);
    }
}
