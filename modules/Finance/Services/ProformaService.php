<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Finance\AmountInput;
use Modules\Finance\Money;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\ProformaItemRepository;
use Modules\Finance\ProformaRepository;

use function App\Infrastructure\Theme\t;

final class ProformaService
{
    public function __construct(
        private readonly ProformaRepository $proformas,
        private readonly ProformaItemRepository $items,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly TaxCalculationService $tax,
        private readonly AuthorizationEngine $authorization,
        private readonly DocumentHeaderGuard $headerGuard = new DocumentHeaderGuard(),
        private readonly ?FinanceDocumentLinkRepository $documentLinks = null,
    ) {
    }

    public function list(array $actingUser): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::QUOTES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = $scope === Scope::OWN ? ['owner_id' => $actingUser['id']] : [];

        return $this->proformas->search($filters);
    }

    /**
     * One page of the proforma list with the list screen's filters
     * (status, search, date_from, date_to). Scope as in list().
     *
     * @return array{rows: array, total: int}
     */
    public function listPage(array $actingUser, array $filters, int $limit, int $offset): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::QUOTES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = array_intersect_key($filters, array_flip(['status', 'search', 'date_from', 'date_to']));
        if ($scope === Scope::OWN) {
            $filters['owner_id'] = $actingUser['id'];
        }

        return [
            'rows' => $this->proformas->search($filters, $limit, $offset),
            'total' => $this->proformas->countSearch($filters),
        ];
    }

    public function find(array $actingUser, int $id): array
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_VIEW, $proforma);

        return $proforma;
    }

    public function itemsFor(int $proformaId): array
    {
        return $this->items->forProforma($proformaId);
    }

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::QUOTES_CREATE)) {
            throw new AuthorizationException();
        }

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        $taxResult = $this->calculateTax($lines, $header);
        $lines = self::withLineTaxes($lines, $taxResult);

        $header['organization_id'] = $actingUser['organization_id'];
        $header['owner_id'] = $actingUser['id'];
        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];
        $header['status'] = 'draft';

        $id = $this->proformas->create($header);
        $this->saveLines($id, $lines);

        return $this->proformas->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_EDIT, $proforma);

        if ($proforma['converted_invoice_id'] !== null) {
            throw new ValidationException(['status' => ['This Proforma has already been converted to an Invoice and can no longer be edited.']]);
        }

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        $taxResult = $this->calculateTax($lines, $header);
        $lines = self::withLineTaxes($lines, $taxResult);

        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];

        $this->proformas->updateProforma($id, $header);
        $this->items->deleteAllFor($id);
        $this->saveLines($id, $lines);

        return $this->proformas->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_DELETE, $proforma);

        if ($proforma['converted_invoice_id'] !== null) {
            throw new ValidationException(['status' => ['This Proforma has already been converted to an Invoice and can no longer be deleted.']]);
        }

        $this->proformas->deleteProforma($id);
        // Its contract link (docs/contract-finance-design.md) goes with it.
        $this->documentLinks?->deleteForDocument('proforma', $id);
    }

    /** @return array<int, array{description: string, product_id: ?int, quantity: string, unit_price: string, discount_type: string, discount_value: string, line_total: string}> */
    private function validatedLines(array $input): array
    {
        $rows = (array) ($input['items'] ?? []);
        // Stage 5.2 (L2): the document currency's money / unit-price precision.
        $moneyDecimals = Money::decimalsFor((int) ($input['currency_id'] ?? 0));
        $priceDecimals = Money::unitPriceDecimalsFor((int) ($input['currency_id'] ?? 0));
        $lines = [];

        foreach ($rows as $row) {
            // A row = product code + product name + free description (شرح).
            // It counts when it has a name or a description; the
            // description column (required) falls back to the name.
            $itemCode = mb_substr(trim((string) ($row['item_code'] ?? '')), 0, 50);
            $itemName = mb_substr(trim((string) ($row['item_name'] ?? '')), 0, 255);
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '' && $itemName === '') {
                continue;
            }
            if ($description === '') {
                $description = $itemName;
            }

            // Remediation P3: normalized ("1,000" → "1000") and strictly validated
            // before any bcmath call — malformed input is a 422, never a 500.
            $lineNo = count($lines) + 1;
            $quantity = AmountInput::normalize($row['quantity'] ?? '1');
            $unitPrice = AmountInput::normalize($row['unit_price'] ?? '0');
            $discountType = in_array($row['discount_type'] ?? 'none', ['none', 'percentage', 'fixed'], true) ? $row['discount_type'] : 'none';
            $discountValue = AmountInput::normalize($row['discount_value'] ?? '0');
            if ($quantity === null) {
                throw new ValidationException(['items' => [t('finance.error.line_quantity', ['line' => $lineNo])]]);
            }
            if ($unitPrice === null) {
                throw new ValidationException(['items' => [t('finance.error.line_unit_price', ['line' => $lineNo])]]);
            }
            if ($discountValue === null) {
                if ($discountType !== 'none') {
                    throw new ValidationException(['items' => [t('finance.error.line_discount', ['line' => $lineNo])]]);
                }
                $discountValue = '0'; // no discount: the value is not used
            }
            // Stage 5.2 (L2): input precision — never silently truncated or rounded.
            if (Money::fractionDigits($quantity) > Money::QUANTITY_DECIMALS) {
                throw new ValidationException(['items' => [t('finance.error.line_quantity_precision', ['line' => $lineNo, 'max' => Money::QUANTITY_DECIMALS])]]);
            }
            if (Money::fractionDigits($unitPrice) > $priceDecimals) {
                throw new ValidationException(['items' => [t('finance.error.line_unit_price_precision', ['line' => $lineNo, 'max' => $priceDecimals])]]);
            }
            $discountDecimals = $discountType === 'percentage' ? Money::PERCENT_DECIMALS : $moneyDecimals;
            if ($discountType !== 'none' && Money::fractionDigits($discountValue) > $discountDecimals) {
                throw new ValidationException(['items' => [t('finance.error.line_discount_precision', ['line' => $lineNo, 'max' => $discountDecimals])]]);
            }

            $gross = bcmul($quantity, $unitPrice, Money::SCALE);
            if ($discountType === 'percentage' && bccomp($discountValue, '100', 18) > 0) {
                throw new ValidationException(['items' => [t('finance.error.line_discount_percent', ['line' => $lineNo])]]);
            }
            if ($discountType === 'fixed' && bccomp($discountValue, $gross, Money::SCALE) > 0) {
                throw new ValidationException(['items' => [t('finance.error.line_discount_fixed', ['line' => $lineNo])]]);
            }
            $discountAmount = match ($discountType) {
                'percentage' => bcmul($gross, bcdiv($discountValue, '100', Money::SCALE), Money::SCALE),
                'fixed' => $discountValue,
                default => '0',
            };

            $lineTotal = bcsub($gross, $discountAmount, Money::SCALE);
            if (bccomp($lineTotal, '0', Money::SCALE) < 0) {
                $lineTotal = '0';
            }

            $lines[] = [
                'product_id' => isset($row['product_id']) && $row['product_id'] !== '' ? (int) $row['product_id'] : null,
                'item_code' => $itemCode !== '' ? $itemCode : null,
                'item_name' => $itemName !== '' ? $itemName : null,
                'description' => mb_substr($description, 0, 255),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'line_total' => $lineTotal,
            ];
        }

        if ($lines === []) {
            throw new ValidationException(['items' => ['Add at least one line item.']]);
        }

        return $lines;
    }

    private function validatedHeader(array $actingUser, array $input): array
    {
        if (empty($input['currency_id'])) {
            throw new ValidationException(['currency_id' => ['Currency is required.']]);
        }

        // F1: the contact must be this organization's, the currency active (server-side).
        $this->headerGuard->check($input['contact_id'] ?? null, $input['currency_id']);

        if (!in_array($input['pricing_mode'] ?? null, ['tax_inclusive', 'tax_exclusive'], true)) {
            throw new ValidationException(['pricing_mode' => ['Invalid pricing mode.']]);
        }

        $issueDate = (string) ($input['issue_date'] ?? '');
        if ($issueDate === '') {
            throw new ValidationException(['issue_date' => ['Issue date is required.']]);
        }

        $profile = $this->financialProfiles->find((int) $actingUser['organization_id']);

        return [
            'contact_id' => isset($input['contact_id']) && $input['contact_id'] !== '' ? (int) $input['contact_id'] : null,
            'issue_date' => $issueDate,
            'expiry_date' => isset($input['expiry_date']) && $input['expiry_date'] !== '' ? $input['expiry_date'] : null,
            'currency_id' => (int) $input['currency_id'],
            'pricing_mode' => $input['pricing_mode'],
            'tax_profile_id' => isset($input['tax_profile_id']) && $input['tax_profile_id'] !== '' ? (int) $input['tax_profile_id'] : ($profile['tax_profile_id'] ?? null),
            // Stage 5.2 (L2): stored normalized ("1,000" → 1000); a malformed value is kept as typed
            // so calculateTax() rejects it with a validation message (never stored).
            'invoice_discount' => AmountInput::normalize($input['invoice_discount'] ?? '0') ?? (string) ($input['invoice_discount'] ?? '0'),
            'terms' => isset($input['terms']) && trim((string) $input['terms']) !== '' ? trim((string) $input['terms']) : null,
            'notes' => isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null,
            // Optional number typed by the user; shown instead of the system number.
            'custom_number' => trim((string) ($input['custom_number'] ?? '')) !== '' ? mb_substr(trim((string) $input['custom_number']), 0, 50) : null,
        ];
    }

    private function calculateTax(array $lines, array $header): array
    {
        // Stage 5.2 (L2/L3): exact scale-18 sums; the subtotal and every total round to the
        // document currency's precision (Money / RoundingService, half-up). The invoice-level
        // discount is a money amount: non-negative, currency precision, at most the subtotal.
        $decimals = Money::decimalsFor((int) $header['currency_id']);
        $discount = AmountInput::normalize($header['invoice_discount'] ?? '0');
        if ($discount === null || Money::fractionDigits($discount) > $decimals) {
            throw new ValidationException(['invoice_discount' => [t('finance.error.amount_precision', ['max' => $decimals])]]);
        }
        $exactSubtotal = '0';
        foreach ($lines as $line) {
            $exactSubtotal = bcadd($exactSubtotal, (string) $line['line_total'], Money::SCALE);
        }
        if (bccomp($discount, $exactSubtotal, Money::SCALE) > 0) {
            throw new ValidationException(['invoice_discount' => [t('finance.error.invoice_discount_exceeds')]]);
        }
        // VAT per row from the products (when any row's product has a VAT %); otherwise as before.
        $perLine = (new LineTaxCalculator(new \Modules\Finance\TaxRuleRepository()))->calculate(
            $lines,
            $header['tax_profile_id'] !== null ? (int) $header['tax_profile_id'] : null,
            (string) $header['issue_date'],
            (string) $header['pricing_mode'],
            $discount,
            $decimals
        );
        if ($perLine !== null) {
            return $perLine;
        }
        if ($header['tax_profile_id'] === null) {
            // No tax profile resolved: untaxed (the UI steers the org to /finance/settings).
            $subtotal = Money::round($exactSubtotal, $decimals);

            return ['subtotal' => $subtotal, 'tax_total' => '0', 'grand_total' => bcsub($subtotal, $discount, Money::SCALE)];
        }

        $taxLines = array_map(
            fn (array $l) => ['amount' => $l['line_total'], 'discount' => '0'],
            $lines
        );

        return $this->tax->calculate(
            $taxLines,
            $header['tax_profile_id'],
            $header['issue_date'],
            $header['pricing_mode'],
            $discount,
            $decimals
        );
    }

    /** Each row's VAT rate / amount from a per-row calculation (NULL when the document is taxed as a whole). */
    private static function withLineTaxes(array $lines, array $taxResult): array
    {
        foreach ($lines as $i => $line) {
            $lines[$i]['tax_rate'] = $taxResult['line_taxes'][$i]['tax_rate'] ?? null;
            $lines[$i]['tax_amount'] = $taxResult['line_taxes'][$i]['tax_amount'] ?? null;
        }

        return $lines;
    }

    private function saveLines(int $proformaId, array $lines): void
    {
        foreach ($lines as $position => $line) {
            $line['proforma_id'] = $proformaId;
            $line['position'] = $position;
            $this->items->create($line);
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireProforma(int $id): array
    {
        $proforma = $this->proformas->findById($id);

        if ($proforma === null) {
            throw new NotFoundException('Proforma not found.');
        }

        return $proforma;
    }

    /**
     * Deletes every id the acting user may delete; the rest are skipped
     * (no permission, not theirs, or locked — e.g. issued / converted).
     * Each one goes through delete() and its own checks.
     *
     * @param array<int|string, mixed> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $actingUser, array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)), 0, 500) as $id) {
            try {
                $this->delete($actingUser, $id);
                $deleted++;
            } catch (AuthorizationException | NotFoundException | ValidationException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
    /**
     * Per-row edit/delete flags for the list page: the same checks
     * update()/delete() apply (permission scope + document state).
     *
     * @return array<int, array{edit: bool, delete: bool, reason?: string}>
     */
    public function rowPermissions(array $actingUser, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $locked = $row['converted_invoice_id'] !== null;
            $result[(int) $row['id']] = [
                'edit' => !$locked && $this->authorization->can($actingUser, Permission::QUOTES_EDIT, $row),
                'delete' => !$locked && $this->authorization->can($actingUser, Permission::QUOTES_DELETE, $row),
            ] + ($locked ? ['reason' => \App\Infrastructure\Theme\t('finance.row_locked_converted')] : []);
        }

        return $result;
    }
}
