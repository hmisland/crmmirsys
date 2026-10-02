<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\InvoiceItemRepository;
use Modules\Finance\InvoiceNumberSeriesRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProformaItemRepository;
use Modules\Finance\ProformaRepository;

final class InvoiceService
{
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
    ) {
    }

    public function list(array $actingUser): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::INVOICES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = $scope === Scope::OWN ? ['owner_id' => $actingUser['id']] : [];

        return $this->invoices->search($filters);
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

        $proformaLines = $this->proformaItems->forProforma($proformaId);
        $lines = array_map(
            fn (array $l) => [
                'product_id' => $l['product_id'],
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

        return $invoice;
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
    }

    /**
     * The approval step. org_admin-only (invoices.issue is never
     * granted to a plain member — see the 0055 grant migration).
     * Allocates the invoice's real number atomically (row-locked
     * series, never a bare MAX()+1) and finalizes it — from this
     * point on the invoice is immutable in this phase; a correction
     * is a Credit Note (12.7), not an edit.
     */
    public function issue(array $actingUser, int $id): array
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

        return $this->invoices->transaction(function () use ($organizationId, $actingUser, $id) {
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
            ]);

            return $this->invoices->findById($id);
        });
    }

    public function cancel(array $actingUser, int $id): array
    {
        $invoice = $this->requireInvoice($id);

        if (!$this->authorization->can($actingUser, Permission::INVOICES_CANCEL, $invoice)) {
            throw new AuthorizationException();
        }

        if ($invoice['status'] === 'cancelled') {
            throw new ValidationException(['status' => ['This invoice is already cancelled.']]);
        }

        $this->invoices->updateInvoice($id, ['status' => 'cancelled']);

        return $this->invoices->findById($id);
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
        $lines = [];

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $quantity = (string) ($row['quantity'] ?? '1');
            $unitPrice = (string) ($row['unit_price'] ?? '0');
            $discountType = in_array($row['discount_type'] ?? 'none', ['none', 'percentage', 'fixed'], true) ? $row['discount_type'] : 'none';
            $discountValue = (string) ($row['discount_value'] ?? '0');

            $gross = bcmul($quantity, $unitPrice, 8);
            $discountAmount = match ($discountType) {
                'percentage' => bcmul($gross, bcdiv($discountValue, '100', 10), 8),
                'fixed' => $discountValue,
                default => '0',
            };

            $lineTotal = bcsub($gross, $discountAmount, 8);
            if (bccomp($lineTotal, '0', 8) < 0) {
                $lineTotal = '0';
            }

            $lines[] = [
                'product_id' => isset($row['product_id']) && $row['product_id'] !== '' ? (int) $row['product_id'] : null,
                'description' => $description,
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
            'invoice_discount' => (string) ($input['invoice_discount'] ?? '0'),
            'terms' => isset($input['terms']) && trim((string) $input['terms']) !== '' ? trim((string) $input['terms']) : null,
            'notes' => isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null,
        ];
    }

    private function calculateTax(array $lines, array $header): array
    {
        if ($header['tax_profile_id'] === null) {
            $subtotal = '0';
            foreach ($lines as $line) {
                $subtotal = bcadd($subtotal, $line['line_total'], 8);
            }
            $taxable = bcsub($subtotal, $header['invoice_discount'], 8);

            return ['subtotal' => $subtotal, 'tax_total' => '0', 'grand_total' => $taxable];
        }

        $taxLines = array_map(fn (array $l) => ['amount' => $l['line_total'], 'discount' => '0'], $lines);

        return $this->tax->calculate(
            $taxLines,
            $header['tax_profile_id'],
            $header['issue_date'],
            $header['pricing_mode'],
            $header['invoice_discount']
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
}
