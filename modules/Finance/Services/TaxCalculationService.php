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
     * @param int $decimals Stage 5.2 (L2): the document currency's money precision (IRR 3, else the configured
     *   precision; Modules\Finance\Money). Formerly a fixed 2. Arithmetic is exact at scale 18; only the
     *   subtotal, each tax line and the totals built from them are rounded (half-up), so
     *   subtotal − discount + tax = grand total exactly for tax-exclusive documents.
     */
    public function calculate(
        array $lines,
        int $taxProfileId,
        string $date,
        string $pricingMode,
        string $invoiceDiscount = '0',
        int $decimals = 2,
    ): array {
        $scale = 18;
        $subtotal = '0';
        foreach ($lines as $line) {
            $lineNet = bcsub($line['amount'], $line['discount'] ?? '0', $scale);
            $subtotal = bcadd($subtotal, $lineNet, $scale);
        }

        $taxableAmount = bcsub($subtotal, $invoiceDiscount, $scale);
        if (bccomp($taxableAmount, '0', $scale) < 0) {
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
                $ratePlusOne = bcadd('1', bcdiv($rule['rate'], '100', $scale), $scale);
                $base = bcdiv($taxableAmount, $ratePlusOne, $scale);
                $taxAmount = bcsub($taxableAmount, $base, $scale);
            } else {
                $taxAmount = bcmul($taxableAmount, bcdiv($rule['rate'], '100', $scale), $scale);
            }

            $taxAmount = RoundingService::round($taxAmount, $decimals);
            $taxLines[] = ['rule_id' => $rule['id'], 'name' => $rule['name'], 'rate' => $rule['rate'], 'amount' => $taxAmount];
            $taxTotal = bcadd($taxTotal, $taxAmount, $scale);
        }

        // Built from rounded parts. Tax-exclusive: round(taxable) + Σ rounded tax lines — the same
        // value as the former round(taxable + tax), since every tax line is already rounded.
        $roundedTaxable = RoundingService::round($taxableAmount, $decimals);
        $grandTotal = $pricingMode === 'tax_inclusive'
            ? $roundedTaxable
            : bcadd($roundedTaxable, $taxTotal, $scale);

        return [
            'subtotal' => RoundingService::round($subtotal, $decimals),
            'invoice_discount' => RoundingService::round($invoiceDiscount, $decimals),
            'taxable_amount' => $roundedTaxable,
            'tax_lines' => $taxLines,
            'tax_total' => RoundingService::round($taxTotal, $decimals),
            'grand_total' => RoundingService::round($grandTotal, $decimals),
            'pricing_mode' => $pricingMode,
            'tax_profile_id' => $taxProfileId,
            'calculated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
