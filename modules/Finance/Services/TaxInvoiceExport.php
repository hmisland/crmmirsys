<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\ContactRepository;
use Modules\Finance\ProductRepository;

use function App\Infrastructure\Theme\t;

/**
 * CSV of issued invoices for the Iranian tax system (one row per item; the
 * tax project imports it — format in docs/tax-invoice-csv.md).
 *
 * Read-only: nothing in the CRM changes. Each row's VAT rate is the one
 * stored on the row (documents taxed per row), else its product's (exempt
 * → 0, else the product's VAT %), on the row's own amount after its own
 * discount; amounts in Rial, rounded to whole Rials.
 * Everything that may not match the tax system (currency, missing 13-digit
 * ID, unit outside the official list, invoice-level discount, a VAT total
 * different from the CRM invoice, …) is reported as a warning — the file is
 * still built once the user confirms.
 */
final class TaxInvoiceExport
{
    public const COLUMNS = [
        'crm_invoice_number', 'issue_date', 'invoice_type', 'payment_type',
        'buyer_type', 'buyer_name', 'buyer_national_id', 'buyer_economic_id', 'buyer_postal_code',
        'row', 'product_code', 'description', 'quantity', 'unit_code', 'unit',
        'unit_price', 'price_before_discount', 'discount', 'price_after_discount',
        'tax_rate', 'tax_amount', 'total_amount', 'note2',
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly ContactRepository $contacts = new ContactRepository(),
    ) {
    }

    /**
     * @param int[] $ids
     * @return array{rows: list<array<string, string>>, problems: list<array{invoice: string, message: string}>, count: int}
     */
    public function build(array $actingUser, array $ids): array
    {
        $rows = [];
        $problems = [];
        $count = 0;
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $invoice = $this->invoices->find($actingUser, $id); // scope-checked
            $number = (string) ($invoice['custom_number'] ?: $invoice['invoice_number'] ?: ('#' . $id));
            $warn = function (string $key, array $replace = []) use (&$problems, $number): void {
                $problems[] = ['invoice' => $number, 'message' => t('tax_export.problem.' . $key, $replace)];
            };
            if ($invoice['status'] !== 'issued') {
                $warn('not_issued');
                continue;
            }
            $count++;
            $currency = (string) (\App\Infrastructure\Finance\CurrencyPrecision::currency((int) $invoice['currency_id'])['code'] ?? '');
            if ($currency !== 'IRR') {
                $warn('currency', ['currency' => $currency]);
            }
            if (bccomp((string) $invoice['invoice_discount'], '0', 18) > 0) {
                $warn('invoice_discount');
            }

            $buyer = $invoice['contact_id'] ? $this->contacts->findById((int) $invoice['contact_id']) : null;
            $nationalId = trim((string) ($buyer['national_id'] ?? ''));
            $economicId = trim((string) ($buyer['economic_code'] ?? ''));
            $type = $invoice['tax_invoice_type'] !== null ? (int) $invoice['tax_invoice_type'] : null;
            if ($type === null) {
                $type = $nationalId !== '' || $economicId !== '' ? 1 : 2;
                $warn('type_unset', ['type' => (string) $type]);
            } elseif ($type === 1 && $nationalId === '' && $economicId === '') {
                $warn('buyer_id');
            }

            $items = $this->invoices->itemsFor($id);
            $inclusive = $invoice['pricing_mode'] === 'tax_inclusive';
            // Share of the invoice-level discount per Rial of row amount (spread pro rata).
            $subtotal = array_reduce($items, fn (string $c, array $i) => bcadd($c, (string) $i['line_total'], 18), '0');
            $invDiscountShare = bccomp($subtotal, '0', 18) > 0 ? bcdiv((string) $invoice['invoice_discount'], $subtotal, 18) : '0';
            $taxSum = '0';
            foreach ($items as $n => $item) {
                $product = $item['product_id'] ? $this->products->findById((int) $item['product_id']) : null;
                $label = (string) ($item['item_name'] ?: $item['description']);
                $code = trim((string) ($item['item_code'] ?? ''));
                if (preg_match('/^\d{13}$/', $code) !== 1) {
                    $code = trim((string) ($product['tax_product_code'] ?? ''));
                }
                if (preg_match('/^\d{13}$/', $code) !== 1) {
                    $warn('code', ['row' => (string) ($n + 1), 'item' => $label]);
                }
                $unitName = (string) ($product['unit'] ?? '');
                $unitCode = self::unitCode($unitName);
                if ($unitCode === null) {
                    $warn('unit', ['row' => (string) ($n + 1), 'item' => $label, 'unit' => $unitName]);
                }
                if (isset($item['tax_rate'])) {
                    $rate = (string) $item['tax_rate']; // fixed on the row when the document was saved
                } elseif ($product !== null && (int) ($product['vat_exempt'] ?? 0) === 1) {
                    $rate = '0';
                } elseif ($product !== null && $product['vat_rate'] !== null) {
                    $rate = (string) $product['vat_rate'];
                } else {
                    $rate = '0';
                    $warn('rate', ['row' => (string) ($n + 1), 'item' => $label]);
                }
                $divisor = $inclusive ? bcadd('1', bcdiv($rate, '100', 18), 18) : '1';
                $quantity = (string) $item['quantity'];
                $unitPrice = bcdiv((string) $item['unit_price'], $divisor, 18);
                $gross = bcmul($quantity, $unitPrice, 18);
                $net = bcdiv((string) $item['line_total'], $divisor, 18);
                $net = bcsub($net, bcmul($net, $invDiscountShare, 18), 18);
                $tax = bcdiv(bcmul($net, $rate, 18), '100', 18);
                $taxSum = bcadd($taxSum, self::rial($tax), 0);
                $rows[] = [
                    'crm_invoice_number' => $number,
                    'issue_date' => (string) $invoice['issue_date'],
                    'invoice_type' => (string) $type,
                    'payment_type' => 'cash',
                    'buyer_type' => $buyer === null ? '' : (!empty($buyer['company_id']) ? '2' : '1'),
                    'buyer_name' => $buyer === null ? '' : trim($buyer['first_name'] . ' ' . ($buyer['last_name'] ?? '')),
                    'buyer_national_id' => $nationalId,
                    'buyer_economic_id' => $economicId,
                    'buyer_postal_code' => trim((string) ($buyer['postal_code'] ?? '')),
                    'row' => (string) ($n + 1),
                    'product_code' => $code,
                    'description' => $label,
                    'quantity' => rtrim(rtrim($quantity, '0'), '.'),
                    'unit_code' => (string) $unitCode,
                    'unit' => $unitName,
                    'unit_price' => self::rial($unitPrice),
                    'price_before_discount' => self::rial($gross),
                    'discount' => self::rial(bcsub($gross, $net, 18)),
                    'price_after_discount' => self::rial($net),
                    'tax_rate' => rtrim(rtrim($rate, '0'), '.') ?: '0',
                    'tax_amount' => self::rial($tax),
                    'total_amount' => bcadd(self::rial($net), self::rial($tax), 0),
                    'note2' => $number,
                ];
            }
            if (abs((float) bcsub($taxSum, self::rial((string) $invoice['tax_total']), 0)) > count($items)) {
                $warn('tax_total', ['crm' => self::rial((string) $invoice['tax_total']), 'rows' => $taxSum]);
            }
        }

        return ['rows' => $rows, 'problems' => $problems, 'count' => $count];
    }

    /** CSV text (UTF-8 with BOM, so Excel shows Persian correctly). */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** Official tax-system unit code for a unit name (or a code), else null. */
    public static function unitCode(string $unit): ?string
    {
        $norm = fn (string $v): string => mb_strtolower((string) preg_replace('/[\s\x{200C}\x{200E}\x{200F}\-_\/().،,]/u', '', strtr($v, ['ي' => 'ی', 'ك' => 'ک', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا'])));
        $wanted = $norm(trim($unit));
        if ($wanted === '') {
            return null;
        }
        foreach ((array) Config::get('tax_units.units', []) as $u) {
            if ($u['code'] === $wanted || $norm($u['name']) === $wanted) {
                return $u['code'];
            }
        }

        return null;
    }

    /** Whole Rials, half-up. */
    private static function rial(string $amount): string
    {
        return bccomp($amount, '0', 18) < 0
            ? bcsub($amount, '0.5', 0)
            : bcadd($amount, '0.5', 0);
    }
}
