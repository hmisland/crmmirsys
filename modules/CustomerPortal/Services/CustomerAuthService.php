<?php

declare(strict_types=1);

namespace Modules\CustomerPortal\Services;

use App\Application\Services\SmsService;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Security\ActionRateLimiter;
use Modules\CustomerPortal\CustomerPortalModule;
use Modules\CustomerPortal\CustomerPortalRepository;
use Modules\CustomerPortal\CustomerSession;

/**
 * Customer sign-in with a one-time code, inside ONE organization (the one
 * whose portal address is used):
 *
 *  1. email or mobile → the matching contact; the code goes to the
 *     contact's email when one is registered (and email can really be
 *     delivered), otherwise to its mobile;
 *  2. national ID → the matching contact's masked email / mobile are
 *     offered and the customer picks one; the ID alone never signs in.
 *
 * Never tells whether an identifier exists: an unknown, ambiguous (several
 * contacts) or unreachable identifier gets the same "if it is registered,
 * a code was sent" answer as a real one, and the code step then simply
 * fails. The national-ID step necessarily shows masked destinations for a
 * match; it is rate-limited hardest and refuses several matches.
 *
 * Codes: 6 random digits, stored only as password_hash(), single-use,
 * short-lived, a few attempts each, a newer code supersedes older ones,
 * cooldown between sends and an hourly cap per contact. Rate limits per
 * client address, per identifier and per organization (SMS cost).
 */
final class CustomerAuthService
{
    public const CODE_LENGTH = 6;
    private const BUCKET_IDENTIFY = 'customer_portal.identify';
    private const BUCKET_NATIONAL_ID = 'customer_portal.national_id';
    private const BUCKET_VERIFY = 'customer_portal.verify';

    public function __construct(
        private readonly CustomerPortalRepository $repository,
        private readonly OtpDelivery $delivery,
        private readonly ActionRateLimiter $limiter,
        private readonly OrganizationEntitlements $entitlements,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The organization of a portal address — only an active one that holds the Customer Portal module. */
    public function organization(string $slug): ?array
    {
        $organization = preg_match('/^[a-z0-9][a-z0-9-]{0,254}$/i', $slug) === 1 ? $this->repository->organizationBySlug($slug) : null;
        if ($organization === null || !$this->entitlements->organizationHas((int) $organization['id'], CustomerPortalModule::KEY)) {
            return null;
        }

        return $organization;
    }

    /**
     * Step 1 (email or mobile). Always ends "a code was sent if registered";
     * only a malformed identifier or a rate limit is reported.
     */
    public function requestCode(array $organization, string $identifier, string $ip): void
    {
        $organizationId = (int) $organization['id'];
        $identifier = trim($identifier);
        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($identifier) : null;
        $mobile = $email === null ? self::mobile($identifier) : null;
        if ($email === null && $mobile === null) {
            throw new ValidationException(['identifier' => ['customer_portal.error.identifier']]);
        }
        $this->limit(self::BUCKET_IDENTIFY, $organizationId, $email ?? $mobile, $ip, 5, 900, 10, 900);

        $ids = $email !== null
            ? $this->repository->contactIdsByEmail($organizationId, $email)
            : $this->repository->contactIdsByMobile($organizationId, self::mobileVariants((string) $mobile));
        if (count($ids) !== 1) {
            if (count($ids) > 1) {
                $this->event('ambiguous_identifier', $organizationId, null, ['kind' => $email !== null ? 'email' : 'mobile', 'matches' => count($ids)]);
            }
            CustomerSession::setPending($organizationId, null, null);

            return;
        }

        $contactId = $ids[0];
        $contact = $this->repository->activeContact($organizationId, $contactId);
        // Priority: the registered email, else the mobile.
        $destination = null;
        $registeredEmail = $email ?? self::validEmail($contact['email'] ?? null);
        if ($registeredEmail !== null && $this->delivery->emailAvailable()) {
            $destination = [OtpDelivery::EMAIL, $registeredEmail];
        } else {
            $registeredMobile = $mobile ?? ($this->mobilesOf($organizationId, $contactId)[0] ?? null);
            if ($registeredMobile !== null) {
                $destination = [OtpDelivery::SMS, $registeredMobile];
            }
        }
        if ($destination === null) {
            $this->event('no_destination', $organizationId, $contactId, []);
            CustomerSession::setPending($organizationId, null, null);

            return;
        }

        $this->issue($organization, $contactId, $destination[0], $destination[1], $ip, false);
    }

    /**
     * Step 1 (national ID): the masked destinations of the one matching
     * contact, or [] (no match, several matches or nothing to send to — the
     * same neutral answer).
     *
     * @return list<string> masked destinations, in order
     */
    public function lookupNationalId(array $organization, string $nationalId, string $ip): array
    {
        $organizationId = (int) $organization['id'];
        $digits = self::nationalIdDigits($nationalId);
        if ($digits === null) {
            throw new ValidationException(['national_id' => ['customer_portal.error.national_id']]);
        }
        $this->limit(self::BUCKET_NATIONAL_ID, $organizationId, $digits, $ip, 3, 3600, 5, 3600);

        $ids = $this->repository->contactIdsByNationalId($organizationId, self::nationalIdVariants($digits));
        if (count($ids) !== 1) {
            if (count($ids) > 1) {
                $this->event('ambiguous_identifier', $organizationId, null, ['kind' => 'national_id', 'matches' => count($ids)]);
            }

            return [];
        }
        $contactId = $ids[0];
        $contact = $this->repository->activeContact($organizationId, $contactId);
        $options = [];
        $email = self::validEmail($contact['email'] ?? null);
        if ($email !== null && $this->delivery->emailAvailable()) {
            $options[] = ['channel' => OtpDelivery::EMAIL, 'destination' => $email, 'hint' => self::maskEmail($email)];
        }
        $mobile = $this->mobilesOf($organizationId, $contactId)[0] ?? null;
        if ($mobile !== null) {
            $options[] = ['channel' => OtpDelivery::SMS, 'destination' => $mobile, 'hint' => self::maskMobile($mobile)];
        }
        if ($options === []) {
            $this->event('no_destination', $organizationId, $contactId, []);

            return [];
        }
        CustomerSession::setChoice($organizationId, $contactId, $options);

        return array_column($options, 'hint');
    }

    /** Step 1b (national ID): send the code to the destination the customer picked from those shown. */
    public function chooseDestination(array $organization, int $index, string $ip): bool
    {
        $organizationId = (int) $organization['id'];
        $choice = CustomerSession::choice($organizationId);
        $option = $choice['options'][$index] ?? null;
        if ($choice === null || !is_array($option)) {
            return false;
        }
        $this->issue($organization, (int) $choice['contact_id'], (string) $option['channel'], (string) $option['destination'], $ip, false);

        return true;
    }

    /**
     * Another code to the same destination as the pending one. The wait is
     * counted from this browser's last step — the same for a real and an
     * unknown identifier, so it reveals nothing.
     *
     * @return int|null seconds to wait (0 = done), null when no sign-in is in progress
     */
    public function resend(array $organization, string $ip): ?int
    {
        $organizationId = (int) $organization['id'];
        $pending = CustomerSession::pending($organizationId);
        if ($pending === null) {
            return null;
        }
        $wait = (int) $pending['at'] + (int) Config::get('customer_portal.resend_cooldown_seconds', 60) - time();
        if ($wait > 0) {
            return $wait;
        }
        $resend = $pending['resend'];
        if (!is_array($resend)) {
            CustomerSession::setPending($organizationId, null, null);

            return 0;
        }
        $this->issue($organization, (int) $resend['contact_id'], (string) $resend['channel'], (string) $resend['destination'], $ip, true);

        return 0;
    }

    /** Whether a sign-in is in progress for this organization in this browser. */
    public function hasPending(array $organization): bool
    {
        return CustomerSession::pending((int) $organization['id']) !== null;
    }

    /**
     * Step 2: the code. On success the customer is signed in (new session id).
     *
     * @return string 'ok' | 'invalid' (wrong, expired, used or unknown — one answer) | 'locked' (no attempts left)
     */
    public function verify(array $organization, string $code, string $ip): string
    {
        $organizationId = (int) $organization['id'];
        if (!$this->limiter->attempt(self::BUCKET_VERIFY, [['subject' => 'ip:' . hash('sha256', $ip), 'max' => 20, 'window' => 900]])) {
            throw new ValidationException(['code' => ['customer_portal.error.too_many']]);
        }
        $code = (string) preg_replace('/\D+/', '', self::latinDigits($code));
        $pending = CustomerSession::pending($organizationId);
        $otpId = $pending['otp_id'] ?? null;
        if ($otpId === null || strlen($code) !== self::CODE_LENGTH) {
            return 'invalid';
        }
        $otp = $this->repository->otp((int) $otpId, $organizationId);
        if ($otp === null || $otp['used_at'] !== null || $otp['invalidated_at'] !== null || strtotime($otp['expires_at'] . ' UTC') <= time()) {
            return 'invalid';
        }
        if (!$this->repository->countAttempt((int) $otp['id'])) {
            return 'locked';
        }
        if (!password_verify($code, (string) $otp['code_hash'])) {
            if ((int) $otp['attempts'] + 1 >= (int) $otp['max_attempts']) {
                $this->event('otp_locked', $organizationId, (int) $otp['contact_id'], []);

                return 'locked';
            }

            return 'invalid';
        }
        if (!$this->repository->markUsed((int) $otp['id']) || $this->repository->activeContact($organizationId, (int) $otp['contact_id']) === null) {
            return 'invalid';
        }
        CustomerSession::login($organizationId, (int) $otp['contact_id'], (string) $organization['slug']);
        $this->event('signed_in', $organizationId, (int) $otp['contact_id'], ['channel' => $otp['channel']]);

        return 'ok';
    }

    // --- Internals -------------------------------------------------------------------

    /** Creates, stores (hashed) and sends a new code; respects the cooldown and the hourly cap silently. */
    private function issue(array $organization, int $contactId, string $channel, string $destination, string $ip, bool $isResend): void
    {
        $organizationId = (int) $organization['id'];
        $resend = ['contact_id' => $contactId, 'channel' => $channel, 'destination' => $destination, 'hint' => $channel === OtpDelivery::EMAIL ? self::maskEmail($destination) : self::maskMobile($destination)];
        $perHour = (int) Config::get('customer_portal.codes_per_contact_per_hour', 5);
        if ((!$isResend && $this->cooldownLeft($organizationId, $contactId) > 0)
            || $this->repository->otpsSince($organizationId, $contactId, date('Y-m-d H:i:s', time() - 3600)) >= $perHour) {
            // Too soon / too many: keep whatever code was sent last (if it is still usable it still works).
            $this->event('code_not_sent_limit', $organizationId, $contactId, []);
            $previous = CustomerSession::pending($organizationId);
            CustomerSession::setPending($organizationId, ($previous['resend']['contact_id'] ?? null) === $contactId ? ($previous['otp_id'] ?? null) : null, $resend);

            return;
        }

        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $this->repository->invalidateActive($organizationId, $contactId);
        $otpId = $this->repository->createOtp([
            'organization_id' => $organizationId,
            'contact_id' => $contactId,
            'channel' => $channel,
            'destination_hint' => $resend['hint'],
            'destination_hash' => hash('sha256', $channel . ':' . $destination),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'max_attempts' => (int) Config::get('customer_portal.otp_max_attempts', 5),
            'expires_at' => date('Y-m-d H:i:s', time() + 60 * (int) Config::get('customer_portal.otp_ttl_minutes', 5)),
            'ip_hash' => hash('sha256', $ip),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $locale = (string) ($organization['default_language'] ?? 'fa');
        if ($this->delivery->send($channel, $destination, $code, ['id' => $organizationId, 'name' => (string) $organization['name']], $locale)) {
            $this->repository->markDelivered($otpId);
        } else {
            $this->event('delivery_failed', $organizationId, $contactId, ['channel' => $channel]);
        }
        unset($code);
        CustomerSession::setPending($organizationId, $otpId, $resend);
    }

    private function cooldownLeft(int $organizationId, int $contactId): int
    {
        $last = $this->repository->lastOtpAt($organizationId, $contactId);
        $cooldown = (int) Config::get('customer_portal.resend_cooldown_seconds', 60);

        return $last === null ? 0 : max(0, strtotime($last . ' UTC') + $cooldown - time());
    }

    /** Per client address, per identifier (hashed) and per organization; a refusal is reported (it reveals nothing about existence). */
    private function limit(string $bucket, int $organizationId, string $identifier, string $ip, int $perIdentifier, int $identifierWindow, int $perIp, int $ipWindow): void
    {
        if (!$this->limiter->attempt($bucket, [
            ['subject' => 'ip:' . hash('sha256', $ip), 'max' => $perIp, 'window' => $ipWindow],
            ['subject' => 'id:' . hash('sha256', $organizationId . ':' . $identifier), 'max' => $perIdentifier, 'window' => $identifierWindow],
            ['subject' => 'org:' . $organizationId, 'max' => 300, 'window' => 3600],
        ])) {
            throw new ValidationException(['identifier' => ['customer_portal.error.too_many']]);
        }
    }

    /** Security-relevant events, without identifiers or codes. */
    private function event(string $event, int $organizationId, ?int $contactId, array $context): void
    {
        $this->logger->info('customer_portal.' . $event, ['organization_id' => $organizationId, 'contact_id' => $contactId] + $context);
    }

    /** @return string[] usable mobiles of the contact, normalized */
    private function mobilesOf(int $organizationId, int $contactId): array
    {
        return array_values(array_unique(array_filter(array_map(fn (string $n): ?string => self::mobile($n), $this->repository->contactMobiles($organizationId, $contactId)))));
    }

    private static function validEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** An Iranian mobile (09xxxxxxxxx) from any common spelling, or null. */
    public static function mobile(string $raw): ?string
    {
        $number = SmsService::normalizeNumber($raw);

        return $number !== null && preg_match('/^09\d{9}$/', $number) === 1 ? $number : null;
    }

    /** @return string[] the spellings a stored mobile may have */
    public static function mobileVariants(string $mobile): array
    {
        $rest = substr($mobile, 1); // 9xxxxxxxxx
        $latin = [$mobile, $rest, '98' . $rest, '+98' . $rest, '0098' . $rest];

        return array_merge($latin, array_map([self::class, 'persianDigits'], $latin), array_map([self::class, 'arabicDigits'], $latin));
    }

    /** The national ID as Latin digits (8–12 digits, separators ignored), or null when malformed. */
    public static function nationalIdDigits(string $raw): ?string
    {
        $digits = (string) preg_replace('/[\s\-.]+/', '', self::latinDigits(trim($raw)));

        return preg_match('/^\d{8,12}$/', $digits) === 1 ? $digits : null;
    }

    /** @return string[] the spellings a stored national ID may have (10-digit IDs also without / with leading zeros) */
    public static function nationalIdVariants(string $digits): array
    {
        $latin = [$digits];
        if (strlen($digits) < 10) {
            $latin[] = str_pad($digits, 10, '0', STR_PAD_LEFT);
        } elseif (strlen($digits) === 10) {
            $latin[] = ltrim($digits, '0');
        }
        $latin = array_values(array_unique(array_filter($latin)));

        return array_merge($latin, array_map([self::class, 'persianDigits'], $latin), array_map([self::class, 'arabicDigits'], $latin));
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }

    public static function maskMobile(string $mobile): string
    {
        return substr($mobile, 0, 4) . '***' . substr($mobile, -4);
    }

    private static function latinDigits(string $value): string
    {
        return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private static function persianDigits(string $value): string
    {
        return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    private static function arabicDigits(string $value): string
    {
        return strtr($value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
