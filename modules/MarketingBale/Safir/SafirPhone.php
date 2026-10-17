<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

use App\Application\Services\SmsService;

/**
 * Recipient numbers for Safir (docs.bale.ai/safir, "ساختار شماره تماس‌های
 * ارسالی"): "98" or "+98" followed by 10 digits, no other characters.
 * We always send "98" + 10 digits, without "+".
 *
 * Input goes through the CRM's own SmsService::normalizeNumber() first
 * (Persian/Arabic digits, +98 / 0098 / 98… / 9… forms → 09XXXXXXXXX), so
 * Bale and SMS read a contact's number the same way. A number that
 * normalizes to a foreign number (00…) is "unsupported" — never turned
 * into a valid-looking Iranian one.
 */
final class SafirPhone
{
    public const UNSUPPORTED = 'unsupported_recipient';
    public const INVALID = 'invalid_address';

    /** @return array{0: ?string, 1: ?string} [98XXXXXXXXXX or null, skip reason when null] */
    public static function classify(string $raw): array
    {
        if (trim($raw) === '') {
            return [null, self::INVALID];
        }
        $normalized = SmsService::normalizeNumber($raw);
        if ($normalized === null) {
            return [null, self::INVALID];
        }
        if (preg_match('/^09\d{9}$/', $normalized) === 1) {
            return ['98' . substr($normalized, 1), null];
        }

        return [null, self::UNSUPPORTED];
    }

    public static function normalize(string $raw): ?string
    {
        return self::classify($raw)[0];
    }

    /** Whether $value already is exactly what Safir documents ("98" + 10 digits). */
    public static function isSafirFormat(string $value): bool
    {
        return preg_match('/^98\d{10}$/', $value) === 1;
    }
}
