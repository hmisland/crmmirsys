<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\OrganizationService;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinancialProfileRepository;

/**
 * Builds the printable view model of an invoice or a proforma: seller
 * (organization profile, falling back to the Finance profile for the
 * legal name and tax ID), buyer (the linked contact and its company),
 * lines and totals. Read-only, and every read goes through
 * InvoiceService/ProformaService::find(), so the same view permission
 * and record scope apply as on the normal invoice page.
 *
 * Amounts stay decimal strings here; formatting (digits, grouping,
 * calendar, words) happens in the template via LocaleFormatter.
 */
final class DocumentPrintService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ProformaService $proformas,
        private readonly CreditNoteRepository $creditNotes,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly OrganizationRepository $organizations,
        private readonly OrganizationService $organizationService,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly CurrencyRepository $currencies,
        private readonly ?AuthorizationEngine $authorization = null,
    ) {
    }

    /**
     * Patch B: 'credited' and 'payable' (grand total minus credits) are
     * null unless the user holds credit_notes.view; the template then shows
     * the grand total instead. 'remaining' and 'status' still account for
     * every credit note.
     */
    public function invoice(array $actingUser, int $id): array
    {
        $invoice = $this->invoices->find($actingUser, $id);
        $showCredits = $this->authorization !== null
            && $this->authorization->can($actingUser, Permission::CREDIT_NOTES_VIEW, $invoice);
        $credited = $this->creditNotes->totalCreditedForInvoice($id);
        $payable = bcsub((string) $invoice['grand_total'], $credited, 18);
        $remaining = bcsub($payable, (string) $invoice['paid_total'], 18);
        if (bccomp($remaining, '0', 18) < 0) {
            $remaining = '0';
        }

        return [
            'type' => 'invoice',
            'number' => ($invoice['custom_number'] ?? '') !== '' ? $invoice['custom_number'] : ($invoice['invoice_number'] ?? ('#' . $invoice['id'])),
            'is_draft' => $invoice['status'] === 'draft',
            'issue_date' => $invoice['issue_date'],
            'second_date' => $invoice['due_date'],
            'status' => $invoice['status'] === 'issued' ? PaymentService::paymentStatus($invoice, $credited) : $invoice['status'],
            'seller' => $this->seller((int) $invoice['organization_id']),
            'buyer' => $this->buyer($invoice['contact_id']),
            'items' => $this->lines($this->invoices->itemsFor($id)),
            'currency' => $this->currency((int) $invoice['currency_id']),
            'totals' => [
                'subtotal' => (string) $invoice['subtotal'],
                'discount' => (string) $invoice['invoice_discount'],
                'tax' => (string) $invoice['tax_total'],
                'grand' => (string) $invoice['grand_total'],
                // Optional final rounding (migration 0024), as snapshotted at issuance; null = not rounded.
                'unrounded' => (int) ($invoice['final_rounding'] ?? 0) === 1 ? (string) $invoice['unrounded_total'] : null,
                'rounding_adjustment' => (int) ($invoice['final_rounding'] ?? 0) === 1 ? (string) $invoice['rounding_adjustment'] : null,
                'credited' => $showCredits && bccomp($credited, '0', 18) > 0 ? $credited : null,
                'payable' => $showCredits ? $payable : null,
                'paid' => (string) $invoice['paid_total'],
                'remaining' => $remaining,
            ],
            'terms' => $invoice['terms'],
            'notes' => $invoice['notes'],
        ];
    }

    public function proforma(array $actingUser, int $id): array
    {
        $proforma = $this->proformas->find($actingUser, $id);

        return [
            'type' => 'proforma',
            // Proformas have no number series of their own; a stable, readable reference from the id.
            'number' => ($proforma['custom_number'] ?? '') !== '' ? $proforma['custom_number'] : 'PF-' . str_pad((string) $proforma['id'], 6, '0', STR_PAD_LEFT),
            'is_draft' => $proforma['status'] === 'draft',
            'issue_date' => $proforma['issue_date'],
            'second_date' => $proforma['expiry_date'],
            'status' => $proforma['status'],
            'seller' => $this->seller((int) $proforma['organization_id']),
            'buyer' => $this->buyer($proforma['contact_id']),
            'items' => $this->lines($this->proformas->itemsFor($id)),
            'currency' => $this->currency((int) $proforma['currency_id']),
            'totals' => [
                'subtotal' => (string) $proforma['subtotal'],
                'discount' => (string) $proforma['invoice_discount'],
                'tax' => (string) $proforma['tax_total'],
                'grand' => (string) $proforma['grand_total'],
                'unrounded' => null,
                'rounding_adjustment' => null,
                'credited' => null,
                'payable' => (string) $proforma['grand_total'],
                'paid' => null,
                'remaining' => null,
            ],
            'terms' => $proforma['terms'],
            'notes' => $proforma['notes'],
        ];
    }

    private function seller(int $organizationId): array
    {
        $organization = $this->organizations->findById($organizationId) ?? [];
        $profile = $this->financialProfiles->find($organizationId) ?? [];

        $profileName = ($profile['entity_type'] ?? 'legal') === 'natural'
            ? trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))
            : trim((string) ($profile['legal_name'] ?? ''));

        return [
            'name' => $profileName !== '' ? $profileName : (string) ($organization['name'] ?? ''),
            'national_id' => $organization['national_id'] ?? null,
            // The Finance profile's tax ID is the economic code when the profile itself has none.
            'economic_code' => ($organization['economic_code'] ?? null) ?: ($profile['tax_identification_number'] ?? null),
            'registration_number' => $organization['registration_number'] ?? null,
            'address' => $organization['address'] ?? null,
            'postal_code' => $organization['postal_code'] ?? null,
            'phone' => $organization['phone'] ?? null,
            'logo_path' => $organization !== [] ? $this->organizationService->logoPath($organization) : null,
        ];
    }

    private function buyer(mixed $contactId): ?array
    {
        $contact = $contactId !== null ? $this->contacts->findById((int) $contactId) : null; // tenant-scoped

        if ($contact === null) {
            return null;
        }

        $company = $contact['company_id'] !== null ? $this->companies->findById((int) $contact['company_id']) : null;

        return [
            'name' => trim($contact['first_name'] . ' ' . ($contact['last_name'] ?? '')),
            'company' => $company['name'] ?? null,
            'national_id' => $contact['national_id'] ?? null,
            'economic_code' => $contact['economic_code'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'email' => $contact['email'] ?? null,
            // Joined by the template with the locale's own list separator.
            'address_parts' => array_values(array_filter(
                [$contact['province'] ?? null, $contact['city'] ?? null, $contact['address'] ?? null],
                fn ($v) => $v !== null && trim((string) $v) !== ''
            )),
            'postal_code' => $contact['postal_code'] ?? null,
        ];
    }

    /** Gross, discount and net per line, recomputed from the stored line exactly as the tax engine does. */
    private function lines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $gross = bcmul((string) $item['quantity'], (string) $item['unit_price'], 18);
            $discount = match ($item['discount_type']) {
                'percentage' => bcdiv(bcmul($gross, (string) $item['discount_value'], 18), '100', 18),
                'fixed' => (string) $item['discount_value'],
                default => '0',
            };

            $name = (string) ($item['item_name'] ?? $item['product_name'] ?? '');
            $lines[] = [
                'code' => (string) ($item['item_code'] ?? $item['product_sku'] ?? ''),
                'name' => $name !== '' ? $name : (string) $item['description'],
                // Shown under the name only when it adds something.
                'note' => $name !== '' && $item['description'] !== $name ? (string) $item['description'] : '',
                'description' => $item['description'],
                'quantity' => rtrim(rtrim((string) $item['quantity'], '0'), '.'),
                'unit_price' => (string) $item['unit_price'],
                'gross' => $gross,
                'discount' => $discount,
                'discount_percent' => $item['discount_type'] === 'percentage' ? rtrim(rtrim((string) $item['discount_value'], '0'), '.') : null,
                'net' => (string) $item['line_total'],
            ];
        }

        return $lines;
    }

    private function currency(int $currencyId): array
    {
        $currency = $this->currencies->findById($currencyId) ?? [];

        return [
            'code' => (string) ($currency['code'] ?? ''),
            'name' => (string) ($currency['name'] ?? ''),
            'decimals' => (int) ($currency['decimal_precision'] ?? 0),
        ];
    }
}
