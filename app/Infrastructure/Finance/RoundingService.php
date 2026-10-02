<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

/**
 * Per the architecture's explicit rule: no module invents its own
 * rounding logic, and currency conversion/tax math never uses PHP
 * floats. This is the one place any monetary value gets rounded.
 */
final class RoundingService
{
    /** Round-half-up to $precision decimal places, computed entirely in bcmath — never float arithmetic. */
    public static function round(string $value, int $precision = 2): string
    {
        $isNegative = bccomp($value, '0', 20) < 0;
        $absValue = $isNegative ? bcmul($value, '-1', 20) : $value;

        $factor = bcpow('10', (string) $precision, 0);
        $shifted = bcmul($absValue, $factor, 20);
        $roundedUpAbs = bcadd($shifted, '0.5', 0);
        $result = bcdiv($roundedUpAbs, $factor, $precision);

        return $isNegative && bccomp($result, '0', $precision) !== 0 ? bcmul($result, '-1', $precision) : $result;
    }
}
