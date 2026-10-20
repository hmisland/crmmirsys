<?php

declare(strict_types=1);

namespace Modules\WooCommerce\ContactSync;

use App\Application\Services\SmsService;
use App\Infrastructure\Geography\IranProvinces;

/**
 * Per-shop field mapping: turns one woo_customers row (billing data of
 * the customer's latest order) into the values the CRM contact fields
 * accept. Pure — no database, no side effects.
 *
 * Destinations are the existing contact fields (contacts + the contact
 * form's quick mobile / landline, see ContactService). Each one has a
 * fixed list of allowed WooCommerce sources; "" = do not import.
 *
 * WooCommerce has ONE generic "phone". It is never copied into both
 * mobile and landline: the "phone" setting decides —
 *   auto     an Iranian mobile (09…) → mobile; an Iranian landline
 *            (0 + area code, 11 digits) → landline; anything else
 *            (e.g. a foreign number of unknown kind) is not imported;
 *   mobile   only an Iranian mobile, otherwise not imported;
 *   landline the number as a landline;
 *   ""       not imported.
 * Values that are empty or invalid become null (never "erase" data).
 */
final class ContactFieldMapper
{
    /** destination => allowed sources (first = default) */
    public const SOURCES = [
        'first_name' => ['first_name'],
        'last_name' => ['last_name'],
        'address' => ['address', 'address_1'],
        'city' => ['city'],
        'province' => ['state'],
        'postal_code' => ['postcode'],
        'country' => ['country'],
        'email' => ['email'],
        'phone' => ['auto', 'mobile', 'landline'],
    ];

    /**
     * WooCommerce's own state codes for Iran (woocommerce/i18n/states.php)
     * → the CRM's province names (IranProvinces).
     */
    public const IRAN_STATE_CODES = [
        'EAZ' => 'آذربایجان شرقی', 'WAZ' => 'آذربایجان غربی', 'ADL' => 'اردبیل', 'ESF' => 'اصفهان', 'ABZ' => 'البرز',
        'ILM' => 'ایلام', 'BHR' => 'بوشهر', 'THR' => 'تهران', 'CHB' => 'چهارمحال و بختیاری', 'SKH' => 'خراسان جنوبی',
        'RKH' => 'خراسان رضوی', 'NKH' => 'خراسان شمالی', 'KHZ' => 'خوزستان', 'ZJN' => 'زنجان', 'SMN' => 'سمنان',
        'SBN' => 'سیستان و بلوچستان', 'FRS' => 'فارس', 'GZN' => 'قزوین', 'QHM' => 'قم', 'KRD' => 'کردستان',
        'KRN' => 'کرمان', 'KRH' => 'کرمانشاه', 'KBD' => 'کهگیلویه و بویراحمد', 'GLS' => 'گلستان', 'GIL' => 'گیلان',
        'LRS' => 'لرستان', 'MZN' => 'مازندران', 'MKZ' => 'مرکزی', 'HRZ' => 'هرمزگان', 'HDN' => 'همدان', 'YZD' => 'یزد',
    ];

    /** @return array<string, string> the default mapping: every field from its first source */
    public static function defaults(): array
    {
        return array_map(fn (array $sources): string => $sources[0], self::SOURCES);
    }

    /**
     * A stored / submitted mapping, reduced to allowed values; unknown keys
     * are dropped and missing destinations get their default.
     *
     * @return array<string, string>
     */
    public static function sanitize(mixed $mapping): array
    {
        $mapping = is_array($mapping) ? $mapping : [];
        $clean = [];
        foreach (self::SOURCES as $destination => $sources) {
            $value = $mapping[$destination] ?? $sources[0];
            $clean[$destination] = is_string($value) && ($value === '' || in_array($value, $sources, true)) ? $value : $sources[0];
        }

        return $clean;
    }

    /**
     * @param array $customer woo_customers row
     * @param string $locale organization language, for the country name
     * @return array{first_name: ?string, last_name: ?string, address: ?string, city: ?string, province: ?string, postal_code: ?string, country: ?string, email: ?string, mobile: ?string, landline: ?string}
     */
    public static function map(array $customer, array $mapping, string $locale = 'fa'): array
    {
        $mapping = self::sanitize($mapping);
        $source = fn (string $destination): ?string => $mapping[$destination] === '' ? null : self::text($customer[$mapping[$destination]] ?? null);

        $address = null;
        if ($mapping['address'] === 'address') {
            $parts = array_filter([self::text($customer['address_1'] ?? null), self::text($customer['address_2'] ?? null)]);
            $address = $parts !== [] ? implode('، ', $parts) : null;
        } elseif ($mapping['address'] === 'address_1') {
            $address = self::text($customer['address_1'] ?? null);
        }

        [$mobile, $landline] = self::phone($mapping['phone'], self::text($customer['phone'] ?? null));
        $countryCode = $mapping['country'] !== '' || $mapping['province'] !== '' ? strtoupper((string) self::text($customer['country'] ?? null)) : '';

        return [
            'first_name' => self::cut($source('first_name'), 100),
            'last_name' => self::cut($source('last_name'), 100),
            'address' => self::cut($address, 255),
            'city' => self::cut($source('city'), 100),
            'province' => $mapping['province'] !== '' ? self::province($source('province'), $countryCode) : null,
            'postal_code' => $mapping['postal_code'] !== '' ? self::postalCode($source('postal_code')) : null,
            'country' => $mapping['country'] !== '' ? self::country($countryCode, $locale) : null,
            'email' => $mapping['email'] !== '' ? self::email($customer['email'] ?? null) : null,
            'mobile' => $mobile,
            'landline' => $landline,
        ];
    }

    /** Iranian mobile in the CRM's stored form (09xxxxxxxxx), or null. */
    public static function mobile(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $number = SmsService::normalizeNumber($raw);

        return $number !== null && str_starts_with($number, '09') ? $number : null;
    }

    /**
     * The stored forms the same Iranian mobile can have in existing contacts
     * (typed by hand over the years), for lookups.
     *
     * @return string[]
     */
    public static function mobileVariants(string $mobile): array
    {
        $rest = substr($mobile, 1); // 9xxxxxxxxx

        return [$mobile, $rest, '98' . $rest, '+98' . $rest, '0098' . $rest];
    }

    /** @return array{0: ?string, 1: ?string} [mobile, landline] */
    private static function phone(string $mode, ?string $raw): array
    {
        if ($raw === null || $mode === '') {
            return [null, null];
        }
        $mobile = self::mobile($raw);

        return match ($mode) {
            'mobile' => [$mobile, null],
            'landline' => [null, self::landline($raw, false)],
            default => $mobile !== null ? [$mobile, null] : [null, self::landline($raw, true)],
        };
    }

    /** $iranOnly: accept only an Iranian landline (0 + area code + number, 11 digits, not a mobile). */
    private static function landline(string $raw, bool $iranOnly): ?string
    {
        $text = self::digits(trim($raw));
        $international = str_starts_with($text, '+') || str_starts_with($text, '00');
        $digits = (string) preg_replace('/\D+/', '', $text);
        if ($international && str_starts_with(ltrim($digits, '0'), '98')) {
            $digits = '0' . substr(ltrim($digits, '0'), 2);
            $international = false;
        }
        if ($iranOnly) {
            return !$international && preg_match('/^0[1-8]\d{9}$/', $digits) === 1 ? $digits : null;
        }
        if ($international) {
            $digits = '00' . ltrim($digits, '0');
        }

        return strlen($digits) >= 8 && strlen($digits) <= 20 ? $digits : null;
    }

    private static function province(?string $state, string $countryCode): ?string
    {
        if ($state === null || ($countryCode !== '' && $countryCode !== 'IR')) {
            return null;
        }
        $code = strtoupper($state);
        if (isset(self::IRAN_STATE_CODES[$code])) {
            return self::IRAN_STATE_CODES[$code];
        }
        // Already a province name (Persian WooCommerce plugins store names): accept only a known one.
        $name = strtr($state, ['ي' => 'ی', 'ك' => 'ک']);

        return in_array($name, IranProvinces::provinceNames(), true) ? $name : null;
    }

    private static function country(string $code, string $locale): ?string
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            return null;
        }
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, $locale);
            if (is_string($name) && $name !== '' && $name !== $code) {
                return mb_substr($name, 0, 100);
            }
        }

        return $code;
    }

    private static function postalCode(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = (string) preg_replace('/[^0-9A-Za-z -]/', '', self::digits($raw));
        $value = trim($value);

        return strlen($value) >= 3 && strlen($value) <= 20 ? $value : null;
    }

    private static function email(mixed $value): ?string
    {
        $email = is_string($value) ? strtolower(trim($value)) : '';

        return $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private static function digits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
