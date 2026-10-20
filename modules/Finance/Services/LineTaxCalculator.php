<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Infrastructure\Finance\RoundingService;
use Modules\Finance\ProductRepository;
use Modules\Finance\TaxRuleRepository;

/**
 * VAT per row, from each row's product (Iranian tax system style): an
 * exempt product → 0, else the product's VAT %. A row whose product has no
 * rate (or that has no product) uses the tax profile's rate (sum of its
 * active, non-exempt rules; 0 without a profile).
 *
 * Used only when at least one row's product carries a rate; otherwise
 * calculate() returns null and the document is calculated exactly as
 * before (TaxCalculationService, one tax for the whole document).
 *
 * The invoice-level discount is spread over the rows pro rata before tax.
 * Each row's tax is rounded to the currency precision; the document's tax
 * is the sum of the rounded row taxes.
 */
final class LineTaxCalculator
{
    private const SCALE = 18;

    public function __construct(
        private readonly TaxRuleRepository $rules,
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /**
     * @param list<array{product_id: ?int, line_total: string}> $lines
     * @return array{subtotal: string, invoice_discount: string, taxable_amount: string, tax_lines: array, tax_total: string, grand_total: string, pricing_mode: string, tax_profile_id: ?int, line_taxes: list<array{tax_rate: ?string, tax_amount: ?string}>}|null
     */
    public function calculate(array $lines, ?int $taxProfileId, string $date, string $pricingMode, string $invoiceDiscount, int $decimals): ?array
    {
        $rates = [];
        $any = false;
        foreach ($lines as $i => $line) {
            $rates[$i] = $line['product_id'] ? $this->productRate((int) $line['product_id']) : null;
            $any = $any || $rates[$i] !== null;
        }
        if (!$any) {
            return null;
        }

        $fallback = '0';
        if ($taxProfileId !== null) {
            foreach ($this->rules->activeRulesForProfile($taxProfileId, $date) as $rule) {
                if ($rule['rate_type'] !== 'exempt' && $rule['rate_type'] !== 'zero') {
                    $fallback = bcadd($fallback, (string) $rule['rate'], self::SCALE);
                }
            }
        }

        $subtotal = '0';
        foreach ($lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line['line_total'], self::SCALE);
        }
        $discountShare = bccomp($subtotal, '0', self::SCALE) > 0 ? bcdiv($invoiceDiscount, $subtotal, self::SCALE) : '0';

        $taxTotal = '0';
        $lineTaxes = [];
        foreach ($lines as $i => $line) {
            $rate = $rates[$i] ?? $fallback;
            $base = bcsub((string) $line['line_total'], bcmul((string) $line['line_total'], $discountShare, self::SCALE), self::SCALE);
            $tax = $pricingMode === 'tax_inclusive'
                ? bcsub($base, bcdiv($base, bcadd('1', bcdiv($rate, '100', self::SCALE), self::SCALE), self::SCALE), self::SCALE)
                : bcdiv(bcmul($base, $rate, self::SCALE), '100', self::SCALE);
            $tax = RoundingService::round($tax, $decimals);
            $taxTotal = bcadd($taxTotal, $tax, self::SCALE);
            $lineTaxes[] = ['tax_rate' => number_format((float) $rate, 2, '.', ''), 'tax_amount' => $tax];
        }

        $taxable = RoundingService::round(bcsub($subtotal, $invoiceDiscount, self::SCALE), $decimals);
        $grand = $pricingMode === 'tax_inclusive' ? $taxable : bcadd($taxable, $taxTotal, self::SCALE);

        return [
            'subtotal' => RoundingService::round($subtotal, $decimals),
            'invoice_discount' => RoundingService::round($invoiceDiscount, $decimals),
            'taxable_amount' => $taxable,
            'tax_lines' => [],
            'tax_total' => RoundingService::round($taxTotal, $decimals),
            'grand_total' => RoundingService::round($grand, $decimals),
            'pricing_mode' => $pricingMode,
            'tax_profile_id' => $taxProfileId,
            'line_taxes' => $lineTaxes,
        ];
    }

    /** The product's VAT %: exempt → "0", the set rate, or null when the product has none. */
    private function productRate(int $productId): ?string
    {
        $product = $this->products->findById($productId);
        if ($product === null) {
            return null;
        }
        if ((int) ($product['vat_exempt'] ?? 0) === 1) {
            return '0';
        }

        return $product['vat_rate'] !== null ? (string) $product['vat_rate'] : null;
    }
}
