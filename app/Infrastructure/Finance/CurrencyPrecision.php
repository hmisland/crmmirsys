<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Infrastructure\Repositories\CurrencyRepository;

/**
 * The currency part of the monetary precision policy (Stage 5.2, L2), in Core
 * next to RoundingService so any module or template can use it without
 * depending on Finance:
 * - decimals(): IRR = 3 (approved policy: fractional rials up to 3 digits),
 *   any other currency = its configured currencies.decimal_precision;
 * - displayDecimals(): IRR shows only the significant digits (≤ 3), other
 *   currencies their configured precision;
 * - finalRoundingDecimals(): target of the optional final-invoice rounding —
 *   whole rials for IRR, the configured precision otherwise.
 * Display never changes stored values. Rounding is RoundingService (half-up).
 */
final class CurrencyPrecision
{
    public const IRR = 'IRR';
    public const IRR_DECIMALS = 3;

    /** @var array<int, ?array> */
    private static array $byId = [];

    /** @var array<string, ?array> */
    private static array $byCode = [];

    public static function currency(int $currencyId): ?array
    {
        if (!array_key_exists($currencyId, self::$byId)) {
            self::$byId[$currencyId] = $currencyId > 0 ? (new CurrencyRepository())->findById($currencyId) : null;
        }

        return self::$byId[$currencyId];
    }

    public static function currencyByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!array_key_exists($code, self::$byCode)) {
            self::$byCode[$code] = $code !== '' ? (new CurrencyRepository())->findByCode($code) : null;
        }

        return self::$byCode[$code];
    }

    private static function isIrr(?array $currency): bool
    {
        return $currency !== null && strtoupper((string) ($currency['code'] ?? '')) === self::IRR;
    }

    /** Money decimals (IRR 3; otherwise the configured precision; 2 when the currency is unknown). */
    public static function decimals(?array $currency): int
    {
        if ($currency === null) {
            return 2;
        }

        return self::isIrr($currency) ? self::IRR_DECIMALS : (int) ($currency['decimal_precision'] ?? 2);
    }

    public static function finalRoundingDecimals(?array $currency): int
    {
        return self::isIrr($currency) ? 0 : self::decimals($currency);
    }

    /**
     * SIGNIFICANT fractional digits of a decimal string ("1.500" → 1, "100.000000000000000000" → 0,
     * "0.125" → 3). Trailing zeros carry no value, so they are never "excess precision": a value
     * read back from a DECIMAL(38,18) column, or typed as "1.50", is accepted as what it is.
     */
    public static function fractionDigits(string $value): int
    {
        $dot = strpos($value, '.');
        if ($dot === false) {
            return 0;
        }

        return strlen(rtrim(substr($value, $dot + 1), '0'));
    }

    /** Display digits for $amount: IRR only the significant ones (≤ 3), others the configured precision. */
    public static function displayDecimals(string $amount, ?array $currency): int
    {
        $decimals = self::decimals($currency);
        if (!self::isIrr($currency)) {
            return $decimals;
        }
        $rounded = RoundingService::round(trim($amount) === '' ? '0' : trim($amount), $decimals);

        return str_contains($rounded, '.') ? self::fractionDigits(rtrim(rtrim($rounded, '0'), '.')) : 0;
    }
}
