<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Infrastructure\Finance\RoundingService;
use Modules\Finance\TaxRuleRepository;

/**
 * Deliberately a pure calculator: given line amounts, a tax profile,
 * and a date, it returns a TaxResult array and writes nothing. The
 * caller (Proforma/Invoice services, in later sub-phases) is
 * responsible for persisting the result onto its own record — this
 * class never touches an invoice/quote table, so the exact same
 * calculation can be reused for a live on-screen preview and for the
 * final frozen snapshot without any duplicated logic between them.
 *
 * Discount→tax order (per the architecture, fixed for now — no
 * jurisdiction identified yet needing a different order):
 *   Gross → Line Discount → Subtotal → Invoice Discount → Tax → Grand Total
 */
final class TaxCalculationService
{
    public function __construct(private readonly TaxRuleRepository $rules)
    {
    }

    /**
     * @param array<int, array{amount: string, discount: string}> $lines Each line's gross amount and its own line-level discount, both bcmath decimal strings.
     * @param string $invoiceDiscount A flat amount discount applied after summing the lines, before tax.
     */
    public function calculate(
        array $lines,
        int $taxProfileId,
        string $date,
        string $pricingMode,
        string $invoiceDiscount = '0',
    ): array {
        $subtotal = '0';
        foreach ($lines as $line) {
            $lineNet = bcsub($line['amount'], $line['discount'] ?? '0', 8);
            $subtotal = bcadd($subtotal, $lineNet, 8);
        }

        $taxableAmount = bcsub($subtotal, $invoiceDiscount, 8);
        if (bccomp($taxableAmount, '0', 8) < 0) {
            $taxableAmount = '0';
        }

        $activeRules = $this->rules->activeRulesForProfile($taxProfileId, $date);

        $taxLines = [];
        $taxTotal = '0';

        foreach ($activeRules as $rule) {
            if ($rule['rate_type'] === 'exempt' || $rule['rate_type'] === 'zero') {
                $taxLines[] = ['rule_id' => $rule['id'], 'name' => $rule['name'], 'rate' => '0', 'amount' => '0'];

                continue;
            }

            if ($pricingMode === 'tax_inclusive') {
                // The taxable amount already contains this tax — back it out:
                // base = amount / (1 + rate/100); tax = amount - base.
                $ratePlusOne = bcadd('1', bcdiv($rule['rate'], '100', 10), 10);
                $base = bcdiv($taxableAmount, $ratePlusOne, 8);
                $taxAmount = bcsub($taxableAmount, $base, 8);
            } else {
                $taxAmount = bcmul($taxableAmount, bcdiv($rule['rate'], '100', 10), 8);
            }

            $taxAmount = RoundingService::round($taxAmount, 2);
            $taxLines[] = ['rule_id' => $rule['id'], 'name' => $rule['name'], 'rate' => $rule['rate'], 'amount' => $taxAmount];
            $taxTotal = bcadd($taxTotal, $taxAmount, 8);
        }

        $grandTotal = $pricingMode === 'tax_inclusive'
            ? $taxableAmount
            : bcadd($taxableAmount, $taxTotal, 8);

        return [
            'subtotal' => RoundingService::round($subtotal, 2),
            'invoice_discount' => RoundingService::round($invoiceDiscount, 2),
            'taxable_amount' => RoundingService::round($taxableAmount, 2),
            'tax_lines' => $taxLines,
            'tax_total' => RoundingService::round($taxTotal, 2),
            'grand_total' => RoundingService::round($grandTotal, 2),
            'pricing_mode' => $pricingMode,
            'tax_profile_id' => $taxProfileId,
            'calculated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
