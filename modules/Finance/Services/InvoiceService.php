<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Finance\AmountInput;
use Modules\Finance\Money;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\InvoiceItemRepository;
use Modules\Finance\InvoiceNumberSeriesRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\LockConflict;
use Modules\Finance\ProformaItemRepository;
use Modules\Finance\ProformaRepository;

use function App\Infrastructure\Theme\t;

final class InvoiceService
{
    /** Room for "Invoice cancelled: " within timeline_events.description (VARCHAR 500). */
    private const CANCEL_REASON_MAX = 450;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceItemRepository $items,
        private readonly InvoiceNumberSeriesRepository $numberSeries,
        private readonly ProformaRepository $proformas,
        private readonly ProformaItemRepository $proformaItems,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly TaxCalculationService $tax,
        private readonly AuthorizationEngine $authorization,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly DocumentHeaderGuard $headerGuard = new DocumentHeaderGuard(),
        private readonly ?FinanceDocumentLinkRepository $documentLinks = null,
        private readonly ?TimelineService $timeline = null,
        private readonly CreditNoteRepository $creditNotes = new CreditNoteRepository(),
    ) {
    }

    /** @param array{contact_id?: int} $filters optional narrowing (e.g. one client's invoices); scope filtering is always applied on top. */
    public function list(array $actingUser, array $filters = []): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::INVOICES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = array_intersect_key($filters, ['contact_id' => true]);
        if ($scope === Scope::OWN) {
            $filters['owner_id'] = $actingUser['id'];
        }

        return $this->invoices->search($filters);
    }

    /**
     * One page of the invoice list with the list screen's filters
     * (status, search, date_from, date_to, contact_id). Scope is applied
     * exactly as in list().
     *
     * @return array{rows: array, total: int}
     */
    public function listPage(array $actingUser, array $filters, int $limit, int $offset): array
    {
        $filters = $this->scopedListFilters($actingUser, $filters);

        return [
            'rows' => $this->invoices->search($filters, $limit, $offset),
            'total' => $this->invoices->countSearch($filters),
        ];
    }

    private function scopedListFilters(array $actingUser, array $filters): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::INVOICES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = array_intersect_key($filters, array_flip(['contact_id', 'status', 'search', 'date_from', 'date_to']));
        if ($scope === Scope::OWN) {
            $filters['owner_id'] = $actingUser['id'];
        }

        return $filters;
    }

    public function find(array $actingUser, int $id): array
    {
        $invoice = $this->requireInvoice($id);
        $this->authorizeRecord($actingUser, Permission::INVOICES_VIEW, $invoice);

        return $invoice;
    }

    public function itemsFor(int $invoiceId): array
    {
        return $this->items->forInvoice($invoiceId);
    }

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::INVOICES_CREATE)) {
            throw new AuthorizationException();
        }

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        return $this->persistDraft($actingUser, $header, $lines, null);
    }

    /**
     * The client's stated priority: convert a Proforma directly to a
     * Draft invoice, carrying over its header and line items exactly
     * as they stood — no separate "Order" stage. Anyone who can
     * convert this specific Proforma (quotes.convert, OWN or
     * ORGANIZATION scope) can do this; the new invoice still starts
     * as a Draft and still needs separate org_admin approval
     * (invoices.issue) before it becomes official.
     */
    public function createFromProforma(array $actingUser, int $proformaId): array
    {
        $proforma = $this->proformas->findById($proformaId);
        if ($proforma === null) {
            throw new NotFoundException('Proforma not found.');
        }

        if (!$this->authorization->can($actingUser, Permission::QUOTES_CONVERT, $proforma)) {
            throw new AuthorizationException();
        }

        if (!$this->authorization->can($actingUser, Permission::INVOICES_CREATE)) {
            throw new AuthorizationException();
        }

        if ($proforma['converted_invoice_id'] !== null) {
            throw new ValidationException(['proforma' => ['This Proforma has already been converted to an Invoice.']]);
        }

        // Stage 2 (D-S2-8): ONE transaction whose first statement locks the
        // proforma, and the "already converted" rule re-checked on that locked
        // row — two concurrent conversions can no longer both create an invoice.
        $work = function () use ($actingUser, $proformaId): array {
            $proforma = $this->proformas->lockForUpdate($proformaId);
            if ($proforma === null) {
                throw new NotFoundException('Proforma not found.');
            }
            if ($proforma['converted_invoice_id'] !== null) {
                throw new ValidationException(['proforma' => ['This Proforma has already been converted to an Invoice.']]);
            }

            $proformaLines = $this->proformaItems->forProforma($proformaId);
            $lines = array_map(
                fn (array $l) => [
                    'product_id' => $l['product_id'],
                    'item_code' => $l['item_code'] ?? null,
                    'item_name' => $l['item_name'] ?? null,
                    'description' => $l['description'],
                    'quantity' => $l['quantity'],
                    'unit_price' => $l['unit_price'],
                    'discount_type' => $l['discount_type'],
                    'discount_value' => $l['discount_value'],
                    'line_total' => $l['line_total'],
                ],
                $proformaLines
            );

            $header = [
                'contact_id' => $proforma['contact_id'],
                'issue_date' => date('Y-m-d'),
                'due_date' => null,
                'currency_id' => $proforma['currency_id'],
                'pricing_mode' => $proforma['pricing_mode'],
                'tax_profile_id' => $proforma['tax_profile_id'],
                'invoice_discount' => $proforma['invoice_discount'],
                'terms' => $proforma['terms'],
                'notes' => $proforma['notes'],
            ];

            $invoice = $this->persistDraft($actingUser, $header, $lines, $proformaId);

            $this->proformas->updateProforma($proformaId, [
                'status' => 'converted',
                'converted_invoice_id' => $invoice['id'],
            ]);

            // A proforma made from a contract hands its contract link to the
            // invoice (docs/contract-finance-design.md, D3), and — Stage 2 — its
            // installment link too. No amount is touched.
            foreach (['contract', 'installment'] as $linkedType) {
                $link = $this->documentLinks?->forDocument('proforma', $proformaId, $linkedType);
                if ($link !== null) {
                    $this->documentLinks->create('invoice', (int) $invoice['id'], $linkedType, (int) $link['linked_id'], (string) $link['linked_label'], (int) $actingUser['id']);
                }
            }

            return $invoice;
        };

        // Joins the caller's transaction when one is open (same connection), else its own.
        return $this->documentLinks !== null ? $this->documentLinks->atomically($work) : $this->proformas->transaction($work);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $invoice = $this->requireInvoice($id);
        $this->authorizeRecord($actingUser, Permission::INVOICES_EDIT, $invoice);
        $this->requireDraft($invoice);

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        $taxResult = $this->calculateTax($lines, $header);
        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];

        $this->invoices->updateInvoice($id, $header);
        $this->items->deleteAllFor($id);
        $this->saveLines($id, $lines);

        return $this->invoices->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $invoice = $this->requireInvoice($id);
        $this->authorizeRecord($actingUser, Permission::INVOICES_DELETE, $invoice);
        $this->requireDraft($invoice);

        $this->invoices->deleteInvoice($id);
        // Its contract link (docs/contract-finance-design.md) goes with it.
        $this->documentLinks?->deleteForDocument('invoice', $id);
    }

    /**
     * The approval step. org_admin-only (invoices.issue is never
     * granted to a plain member — see the 0055 grant migration).
     * Allocates the invoice's real number atomically (row-locked
     * series, never a bare MAX()+1) and finalizes it — from this
     * point on the invoice is immutable in this phase; a correction
     * is a Credit Note (12.7), not an edit.
     *
     * Optional final rounding (migration 0024): $finalRounding is the issuer's
     * per-invoice choice (only someone holding invoices.issue gets here); null
     * means the organization default (financial_profiles.invoice_final_rounding,
     * OFF unless enabled). The decision and its result are snapshotted on the
     * row inside the issue transaction: unrounded_total, rounding_precision
     * (IRR 0 = whole rial, otherwise the currency precision), the adjustment,
     * and grand_total = the final payable — so payments, outstanding, credit
     * notes, print and reports all count the adjustment exactly once.
     */
    public function issue(array $actingUser, int $id, ?bool $finalRounding = null): array
    {
        $invoice = $this->requireInvoice($id);

        if (!$this->authorization->can($actingUser, Permission::INVOICES_ISSUE, $invoice)) {
            throw new AuthorizationException();
        }

        $this->requireDraft($invoice);

        if ($this->financialPeriods->isDateLocked($invoice['issue_date'])) {
            throw new ValidationException(['issue_date' => ['This invoice\'s issue date falls inside a closed financial period — it cannot be issued until that period is reopened.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];

        return LockConflict::guard(fn () => $this->invoices->transaction(function () use ($organizationId, $actingUser, $id, $finalRounding) {
            // Stage 5.2 (L5): lock order document → period (shared) → number series.
            // The invoice row is locked and still a draft — two concurrent issues can't
            // both take a number — and its date is re-checked under the period lock.
            $locked = $this->invoices->lockForUpdate($id) ?? throw new NotFoundException('Invoice not found.');
            $this->requireDraft($locked);
            $this->financialPeriods->assertOpenForWrite((string) $locked['issue_date'], 'issue_date', 'This invoice\'s issue date falls inside a closed financial period — it cannot be issued until that period is reopened.');

            $series = $this->numberSeries->lockForUpdate($organizationId);

            if ($series === null) {
                $profile = $this->financialProfiles->find($organizationId);
                $series = $this->numberSeries->createDefault($organizationId, $profile['invoice_prefix'] ?? 'INV-');
            }

            $sequence = (int) $series['next_sequence'];
            $invoiceNumber = $series['prefix'] . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $this->numberSeries->advance($organizationId, $sequence + 1);

            $this->invoices->updateInvoice($id, [
                'invoice_number' => $invoiceNumber,
                'status' => 'issued',
                'issued_by_user_id' => $actingUser['id'],
                'finalized_at' => date('Y-m-d H:i:s'),
            ] + $this->finalRoundingSnapshot($organizationId, $locked, $finalRounding));

            return $this->invoices->findById($id);
        }));
    }

    /**
     * The final-rounding snapshot written at issuance (never recomputed later).
     * Rounding is half-up (RoundingService); the adjustment may be negative.
     */
    private function finalRoundingSnapshot(int $organizationId, array $invoice, ?bool $override): array
    {
        $profile = $this->financialProfiles->find($organizationId);
        $round = $override ?? (bool) (int) ($profile['invoice_final_rounding'] ?? 0);
        $unrounded = (string) $invoice['grand_total'];

        if (!$round) {
            return ['final_rounding' => 0, 'rounding_precision' => null, 'unrounded_total' => $unrounded, 'rounding_adjustment' => '0'];
        }

        $precision = Money::finalRoundingDecimalsFor((int) $invoice['currency_id']);
        $final = Money::round($unrounded, $precision);

        return [
            'final_rounding' => 1,
            'rounding_precision' => $precision,
            'unrounded_total' => $unrounded,
            'rounding_adjustment' => bcsub($final, $unrounded, Money::SCALE),
            'grand_total' => $final,
        ];
    }

    /**
     * Cancel (docs/invoice-cancel-note.md, Patch A).
     * - Draft: allowed as before (no financial effect, no period lock);
     *   the reason is optional.
     * - Issued: allowed only when it has NO payment (paid_total = 0), NO
     *   credit note, and its issue date is in an OPEN financial period —
     *   and only with a reason. A payment or credit note already corrects /
     *   settles the invoice; cancelling on top would drop recorded money from
     *   every total and subtract credits twice in the period report.
     * The checks are repeated on the row locked FOR UPDATE (the same lock
     * PaymentService / CreditNoteService take), so a payment or credit note
     * landing concurrently can't slip through. Every cancel is written to the
     * timeline (subject "invoice") with the user and time, plus the reason.
     * No refund, and nothing about payments or credit notes is changed.
     */
    public function cancel(array $actingUser, int $id, string $reason = ''): array
    {
        $invoice = $this->requireInvoice($id);

        if (!$this->authorization->can($actingUser, Permission::INVOICES_CANCEL, $invoice)) {
            throw new AuthorizationException();
        }

        if ($invoice['status'] === 'cancelled') {
            throw new ValidationException(['status' => ['This invoice is already cancelled.']]);
        }

        $reason = trim($reason);
        if ($invoice['status'] === 'issued') {
            $blocked = $this->cancelBlockReason($invoice);
            if ($blocked !== null) {
                throw new ValidationException(['status' => [t($blocked)]]);
            }
            if ($reason === '') {
                throw new ValidationException(['reason' => [t('invoices.error.cancel_reason_required')]]);
            }
        }
        if (mb_strlen($reason) > self::CANCEL_REASON_MAX) {
            throw new ValidationException(['reason' => [t('invoices.error.cancel_reason_too_long')]]);
        }

        $work = function () use ($actingUser, $id, $reason): void {
            $locked = $this->invoices->lockForUpdate($id);
            if ($locked === null || $locked['status'] === 'cancelled') {
                throw new ValidationException(['status' => ['This invoice is already cancelled.']]);
            }
            if ($locked['status'] === 'issued' && ($blocked = $this->cancelBlockReason($locked)) !== null) {
                throw new ValidationException(['status' => [t($blocked)]]);
            }
            // Stage 5.2 (L5): an issued invoice's period, as a shared locking read after the
            // document lock (cancelBlockReason's read above is not locking).
            if ($locked['status'] === 'issued') {
                $this->financialPeriods->assertOpenForWrite((string) $locked['issue_date'], 'status', t('invoices.cancel_blocked.period_locked'));
            }

            $this->invoices->updateInvoice($id, ['status' => 'cancelled']);
            $this->timeline?->record(
                'invoice',
                $id,
                'invoice_cancelled',
                'Invoice cancelled' . ($reason !== '' ? ': ' . $reason : ''),
                (int) $actingUser['id']
            );
        };
        // Joins the caller's transaction when one is open (same connection), else its own.
        LockConflict::guard(fn () => $this->documentLinks !== null ? $this->documentLinks->atomically($work) : $this->invoices->transaction($work));

        return $this->invoices->findById($id);
    }

    /**
     * Why an ISSUED invoice can't be cancelled (a translation key), or null
     * when it can. Order: payment, credit note, closed period. Drafts and
     * cancelled invoices aren't judged here.
     */
    public function cancelBlockReason(array $invoice): ?string
    {
        if (($invoice['status'] ?? '') !== 'issued') {
            return null;
        }
        if (bccomp((string) $invoice['paid_total'], '0', 18) > 0) {
            return 'invoices.cancel_blocked.has_payments';
        }
        if ($this->creditNotes->forInvoice((int) $invoice['id']) !== []) {
            return 'invoices.cancel_blocked.has_credit_notes';
        }
        if ($this->financialPeriods->isDateLocked((string) $invoice['issue_date'])) {
            return 'invoices.cancel_blocked.period_locked';
        }

        return null;
    }

    private function persistDraft(array $actingUser, array $header, array $lines, ?int $proformaId): array
    {
        $taxResult = $this->calculateTax($lines, $header);

        $header['organization_id'] = $actingUser['organization_id'];
        $header['owner_id'] = $actingUser['id'];
        $header['proforma_id'] = $proformaId;
        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];
        $header['status'] = 'draft';

        $id = $this->invoices->create($header);
        $this->saveLines($id, $lines);

        return $this->invoices->findById($id);
    }

    private function requireDraft(array $invoice): void
    {
        if ($invoice['status'] !== 'draft') {
            throw new ValidationException(['status' => ['This invoice has already been issued and can no longer be edited — issued invoices are immutable. Use a Credit Note for corrections once that feature is available.']]);
        }
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
            'due_date' => isset($input['due_date']) && $input['due_date'] !== '' ? $input['due_date'] : null,
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
        if ($header['tax_profile_id'] === null) {
            $subtotal = Money::round($exactSubtotal, $decimals);

            return ['subtotal' => $subtotal, 'tax_total' => '0', 'grand_total' => bcsub($subtotal, $discount, Money::SCALE)];
        }

        $taxLines = array_map(fn (array $l) => ['amount' => $l['line_total'], 'discount' => '0'], $lines);

        return $this->tax->calculate(
            $taxLines,
            $header['tax_profile_id'],
            $header['issue_date'],
            $header['pricing_mode'],
            $discount,
            $decimals
        );
    }

    private function saveLines(int $invoiceId, array $lines): void
    {
        foreach ($lines as $position => $line) {
            $line['invoice_id'] = $invoiceId;
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

    private function requireInvoice(int $id): array
    {
        $invoice = $this->invoices->findById($id);

        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.');
        }

        return $invoice;
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
            $locked = $row['status'] !== 'draft';
            $result[(int) $row['id']] = [
                'edit' => !$locked && $this->authorization->can($actingUser, Permission::INVOICES_EDIT, $row),
                'delete' => !$locked && $this->authorization->can($actingUser, Permission::INVOICES_DELETE, $row),
            ] + ($locked ? ['reason' => \App\Infrastructure\Theme\t('finance.row_locked_issued')] : []);
        }

        return $result;
    }
}
