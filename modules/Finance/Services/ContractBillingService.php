<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CurrencyRepository;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;

use function App\Infrastructure\Theme\t;

/**
 * Contract ↔ Finance (docs/contract-finance-design.md). Finance owns the
 * integration; the Contracts module is never imported, and its tables are
 * never queried: a contract is read ONLY through
 * RecordTypeRegistry::find('contract', $id, $user), which returns a row
 * only when Contracts is available to the user, they hold contracts.view,
 * the contract is in their organization and inside their record scope.
 * Anything else behaves exactly like "Contracts isn't there".
 *
 * Every amount is still produced by ProformaService / InvoiceService
 * (and TaxCalculationService) from the submitted form: this class only
 * prefills a form, re-checks the source on submit, and stores the link.
 */
final class ContractBillingService
{
    public const LINKED_TYPE = 'contract';

    public function __construct(
        private readonly ProformaService $proformas,
        private readonly InvoiceService $invoices,
        private readonly FinanceDocumentLinkRepository $links,
        private readonly TimelineService $timeline,
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
        private readonly CreditNoteRepository $creditNotes = new CreditNoteRepository(),
        private readonly ?InstallmentService $installments = null,
    ) {
    }

    public function installments(): ?InstallmentService
    {
        return $this->installments;
    }

    /**
     * Form prefill for "New proforma / invoice" from an installment (Stage 2):
     * contact and terms from the contract, the installment's currency, one
     * line for its amount, and (invoice) its due date. Nothing is saved.
     * Unavailable → NotFoundException; not allowed now (void, has an active
     * document, contract not active / expired) → ValidationException.
     *
     * @return array<string, mixed>
     */
    public function draftFromInstallment(array $actingUser, int $installmentId): array
    {
        if ($this->installments === null) {
            throw new \App\Core\Exceptions\NotFoundException();
        }
        [$installment, $contract] = $this->installments->billable($actingUser, $installmentId);

        $currency = $this->currencies->findById((int) $installment['currency_id']);
        $description = $this->label($contract, (int) $contract['id']) . ' — ' . $this->installments->label($installment)
            . (($installment['description'] ?? '') !== '' ? ' — ' . $installment['description'] : '');

        $draft = [
            'contact_id' => !empty($contract['contact_id']) ? (string) $contract['contact_id'] : '',
            'issue_date' => date('Y-m-d'),
            'due_date' => (string) $installment['due_date'],
            'terms' => (string) ($contract['terms'] ?? ''),
            'items' => [[
                'item_name' => '',
                'description' => mb_substr($description, 0, 255),
                'quantity' => '1',
                'unit_price' => $this->plain((string) $installment['amount']),
                'discount_type' => 'none',
                'discount_value' => '0',
                'product_id' => null,
            ]],
            'source_type' => InstallmentService::LINKED_TYPE,
            'source_id' => (string) $installmentId,
        ];
        if ($currency !== null && !empty($currency['is_active'])) {
            $draft['currency_id'] = (string) $currency['id'];
        }

        return $draft;
    }

    /** The contract row if this user may view it, else null (module off, no permission, other org, out of scope, gone). */
    public function contract(array $actingUser, int $contractId): ?array
    {
        if ($contractId <= 0 || $this->recordTypes === null) {
            return null;
        }

        return $this->recordTypes->find(self::LINKED_TYPE, $contractId, $actingUser);
    }

    /**
     * Form prefill for "New proforma / invoice" from a contract, or null when
     * the contract isn't available to this user. Nothing is saved; the user
     * reviews every field and the normal create() validates and computes.
     *
     * @return array<string, mixed>|null
     */
    public function draftFrom(array $actingUser, int $contractId): ?array
    {
        $contract = $this->contract($actingUser, $contractId);
        if ($contract === null) {
            return null;
        }

        $label = $this->label($contract, $contractId);
        $currencyId = '';
        if (!empty($contract['currency_id'])) {
            $currency = $this->currencies->findById((int) $contract['currency_id']);
            $currencyId = $currency !== null && !empty($currency['is_active']) ? (string) $currency['id'] : '';
        }
        $value = $contract['value'] ?? null;
        $price = $value !== null && $value !== '' ? $this->plain((string) $value) : '';

        $draft = [
            'contact_id' => !empty($contract['contact_id']) ? (string) $contract['contact_id'] : '',
            'issue_date' => date('Y-m-d'),
            'terms' => (string) ($contract['terms'] ?? ''),
            'items' => [[
                'item_name' => '',
                'description' => mb_substr($label, 0, 255),
                'quantity' => '1',
                'unit_price' => $price,
                'discount_type' => 'none',
                'discount_value' => '0',
                'product_id' => null,
            ]],
            'source_type' => self::LINKED_TYPE,
            'source_id' => (string) $contractId,
        ];
        if ($currencyId !== '') {
            $draft['currency_id'] = $currencyId; // else the form's organization default applies
        }

        return $draft;
    }

    /**
     * The "created from contract" notice for the form — re-derived on the
     * server, never taken from the request.
     *
     * @return array{id: int, label: string, url: ?string, no_contact: bool}|null
     */
    public function sourceNotice(array $actingUser, int $contractId): ?array
    {
        $contract = $this->contract($actingUser, $contractId);
        if ($contract === null) {
            return null;
        }

        return [
            'type' => self::LINKED_TYPE,
            'id' => $contractId,
            'label' => $this->label($contract, $contractId),
            'url' => $this->recordTypes?->get(self::LINKED_TYPE)?->showUrl($contractId),
            'no_contact' => empty($contract['contact_id']),
        ];
    }

    /**
     * The "created from installment" notice (Stage 2), re-derived on the
     * server: null when the installment or its contract isn't viewable. The
     * submit re-checks everything again (createFromInstallment()).
     *
     * @return array{type: string, id: int, label: string, url: ?string, no_contact: bool}|null
     */
    public function installmentSourceNotice(array $actingUser, int $installmentId): ?array
    {
        try {
            [$installment, $contract] = $this->installments?->billable($actingUser, $installmentId) ?? [null, null];
        } catch (\App\Core\Exceptions\NotFoundException | ValidationException) {
            return null;
        }
        if ($installment === null) {
            return null;
        }
        $contractId = (int) $contract['id'];

        return [
            'type' => InstallmentService::LINKED_TYPE,
            'id' => $installmentId,
            'label' => $this->label($contract, $contractId) . ' — ' . $this->installments->label($installment),
            'url' => $this->recordTypes?->get(self::LINKED_TYPE)?->showUrl($contractId),
            'no_contact' => empty($contract['contact_id']),
        ];
    }

    /**
     * create() of the document + the link + a timeline entry on the contract,
     * atomically. The contract is re-checked here (hidden form fields are
     * never trusted): if the user can't view it, nothing is created.
     * Linking is part of creating — it needs only the document's create
     * permission (checked by create()) plus view access to the contract.
     */
    public function createLinked(array $actingUser, string $documentType, array $input, string $sourceType, int $sourceId): array
    {
        if ($sourceType === InstallmentService::LINKED_TYPE && $this->installments !== null) {
            return $this->createFromInstallment($actingUser, $documentType, $input, $sourceId);
        }

        $contract = $sourceType === self::LINKED_TYPE ? $this->contract($actingUser, $sourceId) : null;
        if ($contract === null || !in_array($documentType, FinanceDocumentLinkRepository::DOCUMENT_TYPES, true)) {
            throw new ValidationException(['source' => [t('finance.error.contract_unavailable')]]);
        }

        $label = $this->label($contract, $sourceId);

        return $this->links->atomically(function () use ($actingUser, $documentType, $input, $sourceId, $label): array {
            $document = $documentType === 'proforma'
                ? $this->proformas->create($actingUser, $input)
                : $this->invoices->create($actingUser, $input);

            $this->links->create($documentType, (int) $document['id'], self::LINKED_TYPE, $sourceId, $label, (int) $actingUser['id']);
            $this->timeline->record(
                self::LINKED_TYPE,
                $sourceId,
                $documentType . '_created',
                ($documentType === 'proforma' ? 'Proforma' : 'Invoice') . ' draft created from this contract',
                (int) $actingUser['id']
            );

            return $document;
        });
    }

    /**
     * A document from an installment (Stage 2): the installment and its
     * contract are re-checked from the server (never the form), the document
     * must be in the installment's currency, and — inside ONE transaction
     * whose first statement locks the installment row — the installment is
     * re-checked as still open before the document, its two links (contract
     * + installment) and a contract timeline entry are written. Two requests
     * can therefore never both attach a document to the same installment.
     * Amounts, tax and numbering stay entirely ProformaService / InvoiceService's.
     */
    private function createFromInstallment(array $actingUser, string $documentType, array $input, int $installmentId): array
    {
        try {
            [$installment, $contract] = $this->installments->billable($actingUser, $installmentId);
        } catch (\App\Core\Exceptions\NotFoundException) {
            throw new ValidationException(['source' => [t('finance.installment.error.unavailable')]]);
        }
        if (!in_array($documentType, FinanceDocumentLinkRepository::DOCUMENT_TYPES, true)) {
            throw new ValidationException(['source' => [t('finance.installment.error.unavailable')]]);
        }
        if ((int) ($input['currency_id'] ?? 0) !== (int) $installment['currency_id']) {
            throw new ValidationException(['currency_id' => [t('finance.installment.error.document_currency')]]);
        }

        $contractId = (int) $contract['id'];
        $contractLabel = $this->label($contract, $contractId);
        $label = $contractLabel . ' — ' . $this->installments->label($installment);

        return $this->links->atomically(function () use ($actingUser, $documentType, $input, $installmentId, $contractId, $contractLabel, $label): array {
            $locked = $this->installments->lockForDocument($installmentId);

            $document = $documentType === 'proforma'
                ? $this->proformas->create($actingUser, $input)
                : $this->invoices->create($actingUser, $input);

            $this->links->create($documentType, (int) $document['id'], self::LINKED_TYPE, $contractId, $contractLabel, (int) $actingUser['id']);
            $this->links->create($documentType, (int) $document['id'], InstallmentService::LINKED_TYPE, $installmentId, $label, (int) $actingUser['id']);
            $this->timeline->record(
                self::LINKED_TYPE,
                $contractId,
                $documentType . '_created',
                ($documentType === 'proforma' ? 'Proforma' : 'Invoice') . ' draft created from installment #' . $locked['sequence'],
                (int) $actingUser['id']
            );

            return $document;
        });
    }

    /** The contract a document is linked to, for display: url null when the contract isn't viewable any more. */
    public function linkOf(array $actingUser, string $documentType, int $documentId): ?array
    {
        $link = $this->links->forDocument($documentType, $documentId, self::LINKED_TYPE);
        if ($link === null) {
            return null;
        }

        $live = $this->contract($actingUser, (int) $link['linked_id']);

        return [
            'label' => $live !== null ? $this->label($live, (int) $link['linked_id']) : (string) $link['linked_label'],
            'url' => $live !== null ? $this->recordTypes?->get(self::LINKED_TYPE)?->showUrl((int) $link['linked_id']) : null,
        ];
    }

    /**
     * Data for the Billing panel on a contract page. Each linked document is
     * read through the existing ProformaService / InvoiceService::find(), so
     * Finance's own permission + record scope apply to every row (a document
     * the user may not open is simply not listed). A section is null when the
     * user lacks its view permission.
     *
     * Summary (docs/project-finance-design.md, Stage 1), per currency, in the
     * invoice's own currency, never summed across currencies. Per ISSUED
     * invoice:
     *   Credited    = Σ its credit notes
     *   Net billed  = Grand total − Credited
     *   Receivable  = max(0, Grand total − Credited − Paid)          → 'balance'
     *   Refund due  = max(0, Paid − (Grand total − Credited))
     * Drafts are never counted. Cancelled invoices are out of every main
     * figure; a payment recorded on one is reported in 'paid_on_cancelled'.
     * 'credited' / 'net_billed' are null unless the user holds
     * credit_notes.view (Receivable / Refund due still account for credits).
     * Stored totals only, bcmath at 18 decimals; nothing is recomputed and no
     * Finance rule (payment, credit note, invoice, cancel, tax, periods) changes.
     *
     * @return array{proformas: ?array, invoices: ?array, summary: array<int, array{currency_id: int, issued: bool, billed: string, credited: ?string, net_billed: ?string, paid: string, balance: string, refund_due: string, paid_on_cancelled: string}>}
     */
    public function panelFor(array $actingUser, int $contractId, \Closure $canView): array
    {
        $collect = function (string $type, \Closure $find) use ($contractId, $actingUser): array {
            $rows = [];
            foreach ($this->links->documentIdsLinkedTo($type, self::LINKED_TYPE, $contractId) as $id) {
                try {
                    $rows[] = $find($actingUser, $id);
                } catch (\App\Core\Exceptions\AuthorizationException | \App\Core\Exceptions\NotFoundException) {
                    // outside the user's scope, or deleted elsewhere: not listed
                }
            }

            return $rows;
        };

        $proformas = $canView('quotes.view') ? $collect('proforma', fn (array $u, int $id) => $this->proformas->find($u, $id)) : null;
        $invoices = $canView('invoices.view') ? $collect('invoice', fn (array $u, int $id) => $this->invoices->find($u, $id)) : null;

        // Only the invoices listed above take part: they already passed
        // invoices.view + record scope (find()) and are this organization's.
        // Credits are read per such invoice through the tenant-scoped
        // repository, so no other invoice or organization can contribute.
        $showCredits = $canView('credit_notes.view');
        $summary = [];
        $entry = function (int $currency) use (&$summary, $showCredits): void {
            $summary[$currency] ??= [
                'currency_id' => $currency, 'issued' => false,
                'billed' => '0', 'credited' => $showCredits ? '0' : null, 'net_billed' => $showCredits ? '0' : null,
                'paid' => '0', 'balance' => '0', 'refund_due' => '0', 'paid_on_cancelled' => '0',
            ];
        };
        foreach ($invoices ?? [] as $invoice) {
            $status = (string) ($invoice['status'] ?? '');
            $currency = (int) $invoice['currency_id'];

            // Cancelled: out of every main figure; a payment recorded on it is
            // surfaced separately (warning) — never as valid revenue.
            if ($status === 'cancelled') {
                if (bccomp((string) $invoice['paid_total'], '0', 18) > 0) {
                    $entry($currency);
                    $summary[$currency]['paid_on_cancelled'] = bcadd($summary[$currency]['paid_on_cancelled'], (string) $invoice['paid_total'], 18);
                }
                continue;
            }
            if ($status !== 'issued') {
                continue; // drafts are listed, never counted
            }

            $entry($currency);
            $grand = (string) $invoice['grand_total'];
            $paid = (string) $invoice['paid_total'];
            $credited = $this->creditNotes->totalCreditedForInvoice((int) $invoice['id']);
            $netBilled = bcsub($grand, $credited, 18);           // Grand Total − Credited
            $receivable = bcsub($netBilled, $paid, 18);          // Grand Total − Credited − Paid
            $refundDue = bcsub($paid, $netBilled, 18);           // Paid − (Grand Total − Credited)

            $s = &$summary[$currency];
            $s['issued'] = true;
            $s['billed'] = bcadd($s['billed'], $grand, 18);
            $s['paid'] = bcadd($s['paid'], $paid, 18);
            $s['balance'] = bcadd($s['balance'], bccomp($receivable, '0', 18) > 0 ? $receivable : '0', 18);
            $s['refund_due'] = bcadd($s['refund_due'], bccomp($refundDue, '0', 18) > 0 ? $refundDue : '0', 18);
            if ($showCredits) {
                $s['credited'] = bcadd($s['credited'], $credited, 18);
                $s['net_billed'] = bcadd($s['net_billed'], $netBilled, 18);
            }
            unset($s);
        }

        return ['proformas' => $proformas, 'invoices' => $invoices, 'summary' => array_values($summary)];
    }

    private function label(array $contract, int $id): string
    {
        $title = trim((string) ($contract['title'] ?? ''));
        $number = trim((string) ($contract['contract_number'] ?? ''));

        return ($title !== '' ? $title : 'Contract #' . $id) . ($number !== '' ? ' (' . $number . ')' : '');
    }

    /** "1250000.500000000000000000" → "1250000.5" (display/prefill only; no arithmetic). */
    private function plain(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
