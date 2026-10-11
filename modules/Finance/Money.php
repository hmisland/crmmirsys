<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Finance\CurrencyPrecision;
use App\Infrastructure\Finance\RoundingService;

/**
 * Finance monetary precision policy (Stage 5.2, L2 / L3 — approved policy).
 *
 * 1. Input precision (validated, never silently truncated):
 *    - money amounts (payments, credit notes, expenses, installments, budget
 *      lines, fixed discounts, invoice discount): at most decimals(currency);
 *    - unit price: at most decimals(currency) + 2;
 *    - quantity: at most 4 (the DECIMAL(18,4) column);
 *    - percentages: at most 4.
 * 2. Internal calculation precision: bcmath scale 18 (= the DECIMAL(38,18)
 *    columns), so quantity × unit price and percentage discounts are exact.
 * 3. Persisted precision: DECIMAL(38,18), unchanged; line totals stored exactly.
 * 4. Rounding boundaries (RoundingService, round HALF-UP — the existing mode):
 *    document subtotal and each tax line round to decimals(currency); the
 *    grand total is built from those rounded parts (so subtotal − discount +
 *    tax = grand total exactly); the optional final-invoice rounding at
 *    issuance rounds the payable to finalRoundingDecimals(currency).
 * 5. Display: CurrencyPrecision::displayDecimals (IRR ≤ 3 significant digits).
 * decimals(): IRR = 3; any other currency = currencies.decimal_precision
 * (App\Infrastructure\Finance\CurrencyPrecision).
 */
final class Money
{
    public const SCALE = 18;
    public const QUANTITY_DECIMALS = 4;
    public const PERCENT_DECIMALS = 4;
    private const UNIT_PRICE_EXTRA = 2;

    public static function decimals(?array $currency): int
    {
        return CurrencyPrecision::decimals($currency);
    }

    public static function decimalsFor(int $currencyId): int
    {
        return CurrencyPrecision::decimals(CurrencyPrecision::currency($currencyId));
    }

    public static function unitPriceDecimalsFor(int $currencyId): int
    {
        return self::decimalsFor($currencyId) + self::UNIT_PRICE_EXTRA;
    }

    public static function finalRoundingDecimalsFor(int $currencyId): int
    {
        return CurrencyPrecision::finalRoundingDecimals(CurrencyPrecision::currency($currencyId));
    }

    public static function fractionDigits(string $value): int
    {
        return CurrencyPrecision::fractionDigits($value);
    }

    public static function round(string $value, int $decimals): string
    {
        return RoundingService::round($value, $decimals);
    }

    /** The exact stored value without insignificant zeros ("1500.500000000000000000" → "1500.5"), for audit text. */
    public static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
