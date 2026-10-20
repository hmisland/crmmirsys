<?php

declare(strict_types=1);

namespace Modules\CustomerPortal;

use App\Infrastructure\Security\Session;

/**
 * The signed-in customer, kept apart from the staff sign-in: its own
 * session keys, never the staff "_auth_user_id", so a customer is never a
 * `users` row, never CurrentUser and never passes staff RBAC. Signing in
 * regenerates the session id (fixation); signing out removes only the
 * customer keys (a staff session in the same browser is left alone).
 *
 * Also keeps the server-side state of a sign-in in progress (which code
 * was sent, which masked destinations were offered) — the browser only
 * ever posts the typed code or the number of a destination it was shown.
 */
final class CustomerSession
{
    private const CUSTOMER = '_customer_portal';
    private const PENDING = '_customer_portal_pending';
    private const CHOICE = '_customer_portal_choice';
    private const LAST_SLUG = '_customer_portal_slug';

    /** A signed-in session lives at most this long, however active. */
    public const MAX_LIFETIME = 8 * 3600;
    /** A sign-in step (code sent, destinations offered) expires after this. */
    public const STEP_LIFETIME = 900;

    public static function login(int $organizationId, int $contactId, string $slug): void
    {
        self::regenerate();
        unset($_SESSION[self::PENDING], $_SESSION[self::CHOICE]);
        $_SESSION[self::CUSTOMER] = ['organization_id' => $organizationId, 'contact_id' => $contactId, 'slug' => $slug, 'since' => time()];
        $_SESSION[self::LAST_SLUG] = $slug;
    }

    /** @return array{organization_id: int, contact_id: int, slug: string, since: int}|null */
    public static function customer(): ?array
    {
        $customer = $_SESSION[self::CUSTOMER] ?? null;
        if (!is_array($customer) || (int) ($customer['since'] ?? 0) < time() - self::MAX_LIFETIME) {
            return null;
        }

        return ['organization_id' => (int) $customer['organization_id'], 'contact_id' => (int) $customer['contact_id'], 'slug' => (string) $customer['slug'], 'since' => (int) $customer['since']];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::CUSTOMER], $_SESSION[self::PENDING], $_SESSION[self::CHOICE]);
        self::regenerate();
    }

    /** New session id (session fixation); a web request always has an active session (Session::start). */
    private static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Session::regenerate();
        }
    }

    /** The slug of the organization the browser last signed in to (where to send it back to). */
    public static function lastSlug(): ?string
    {
        $slug = $_SESSION[self::LAST_SLUG] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    public static function rememberSlug(string $slug): void
    {
        $_SESSION[self::LAST_SLUG] = $slug;
    }

    /**
     * A code was (or, for an unknown identifier, pretended to be) sent.
     * $otpId null = nothing was sent — the verify step then fails like a wrong code.
     */
    public static function setPending(int $organizationId, ?int $otpId, ?array $resend): void
    {
        unset($_SESSION[self::CHOICE]);
        $_SESSION[self::PENDING] = ['organization_id' => $organizationId, 'otp_id' => $otpId, 'resend' => $resend, 'at' => time()];
    }

    /** @return array{organization_id: int, otp_id: ?int, resend: ?array, at: int}|null */
    public static function pending(int $organizationId): ?array
    {
        $pending = $_SESSION[self::PENDING] ?? null;
        if (!is_array($pending) || (int) $pending['organization_id'] !== $organizationId || (int) $pending['at'] < time() - self::STEP_LIFETIME) {
            return null;
        }

        return $pending;
    }

    public static function clearPending(): void
    {
        unset($_SESSION[self::PENDING]);
    }

    /** @param list<array{channel: string, destination: string, hint: string}> $options */
    public static function setChoice(int $organizationId, int $contactId, array $options): void
    {
        unset($_SESSION[self::PENDING]);
        $_SESSION[self::CHOICE] = ['organization_id' => $organizationId, 'contact_id' => $contactId, 'options' => $options, 'at' => time()];
    }

    /** @return array{organization_id: int, contact_id: int, options: list<array>, at: int}|null */
    public static function choice(int $organizationId): ?array
    {
        $choice = $_SESSION[self::CHOICE] ?? null;
        if (!is_array($choice) || (int) $choice['organization_id'] !== $organizationId || (int) $choice['at'] < time() - self::STEP_LIFETIME) {
            return null;
        }

        return $choice;
    }
}
