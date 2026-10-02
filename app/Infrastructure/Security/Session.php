<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Config\Config;

/**
 * Per Phase 0 §32: secure, HttpOnly, SameSite cookies, server-side
 * expiry. Session::start() is called once from the kernel, before any
 * output. login()/logout()/userId() (Phase 2) own the session's
 * *contents* (who is authenticated); everything above them owns only
 * its transport security.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetimeMinutes = (int) Config::get('session.lifetime_minutes', 120);
        $secureCookie = (bool) Config::get('session.secure', true);

        session_set_cookie_params([
            'lifetime' => $lifetimeMinutes * 60,
            'path' => '/',
            'domain' => (string) Config::get('session.domain', ''),
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => (string) Config::get('session.same_site', 'Lax'),
        ]);

        session_name((string) Config::get('session.cookie_name', 'crm_session'));
        session_start();

        self::enforceExpiry($lifetimeMinutes);
    }

    private static function enforceExpiry(int $lifetimeMinutes): void
    {
        $now = time();
        $lastActivity = $_SESSION['_last_activity'] ?? $now;

        if (($now - $lastActivity) > ($lifetimeMinutes * 60)) {
            self::destroy();
            session_start();
        }

        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    private const USER_ID_KEY = '_auth_user_id';

    /**
     * Per Phase 2: only the user's id is stored in the session, never
     * the full profile — AuthMiddleware re-fetches the row fresh on
     * every request, so a role/status change takes effect immediately
     * instead of persisting until the session expires.
     *
     * Session ID regeneration on login is mandatory (session fixation
     * protection, Phase 0 §32/§60): an attacker who fixed a victim's
     * pre-login session id must not inherit an authenticated session.
     */
    public static function login(int $userId): void
    {
        self::regenerate();
        $_SESSION[self::USER_ID_KEY] = $userId;
    }

    public static function logout(): void
    {
        self::destroy();
        session_start();
        self::regenerate();
    }

    public static function userId(): ?int
    {
        $id = $_SESSION[self::USER_ID_KEY] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * One-time flash messages (e.g. "password reset — please log in").
     * Read-once: getFlash() removes the key so a page refresh doesn't
     * redisplay it.
     */
    public static function setFlash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    public static function getFlash(string $key): ?string
    {
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $message;
    }

    private const GUEST_LOCALE_KEY = '_guest_locale';

    /**
     * A logged-in user's language lives on `users.language` — this is
     * only for a visitor who switches language before logging in (the
     * login/register pages themselves), so the choice survives page
     * to page without a user row to store it on yet.
     */
    public static function setGuestLocale(string $locale): void
    {
        $_SESSION[self::GUEST_LOCALE_KEY] = $locale;
    }

    public static function guestLocale(): ?string
    {
        return $_SESSION[self::GUEST_LOCALE_KEY] ?? null;
    }
}
