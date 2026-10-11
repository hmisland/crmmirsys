<?php

declare(strict_types=1);

namespace Modules\Finance;

/**
 * Server-side parsing of a typed money / quantity value (Finance remediation P3).
 *
 * The same rule Expenses already apply (Stage 4): thousands separators and
 * spaces are removed ("1,000.50" → "1000.50"), then the value must be a
 * plain non-negative decimal of at most 20 integer and 18 fractional digits
 * — the DECIMAL(38,18) columns. Anything else ("abc", "1e3", "-5", "")
 * is null, so callers answer with a ValidationException (422 / flash)
 * instead of letting bcmath throw an uncaught ValueError (500).
 */
final class AmountInput
{
    /** @return string|null the normalized decimal, or null when malformed or negative */
    public static function normalize(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = str_replace([',', ' '], '', trim((string) $raw));

        return preg_match('/^\d{1,20}(\.\d{1,18})?$/', $value) === 1 ? $value : null;
    }
}
