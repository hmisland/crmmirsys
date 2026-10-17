<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CurrencyRepository;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InstallmentRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\Money;
use Modules\Finance\ProformaRepository;

use function App\Infrastructure\Theme\t;

/**
 * Contract installment schedule (docs/project-finance-design.md, Stage 2).
 *
 * An installment is a PLANNED amount + due date on a contract. It is not an
 * invoice, a receivable or a payment, and it never feeds a financial total.
 * Its status is derived from the one active document linked to it
 * (finance_document_links, linked_type = 'installment'):
 *   void            stored; terminal
 *   unpaid          no active document, or a proforma / draft invoice
 *   issued          issued invoice, nothing paid, something still owed
 *   partially_paid  issued invoice, part paid
 *   paid            issued invoice, nothing left to collect (grand − credited − paid ≤ 0)
 * "Overdue" is a flag (due date passed, not paid / void), computed on read.
 * Active document = a proforma not yet converted, or an invoice not cancelled.
 *
 * Contracts are read ONLY through RecordTypeRegistry::find() (module
 * available, contracts.view, same organization, record scope); the
 * Contracts module is never imported and its tables never queried.
 * Managing the schedule needs contracts.edit on the contract (the key that
 * already governs other non-term changes on a contract in any status, e.g.
 * its links). Creating a document from an installment needs the document's
 * own create permission (checked by ProformaService / InvoiceService).
 *
 * Contract status: create / edit only while `active`; documents from an
 * installment while `active` or `expired`; nothing new once `terminated`.
 * Voiding (reason required, no active document) is allowed in any status.
 * Installments are not postings, so no financial-period lock applies here;
 * documents keep every existing period rule.
 */
final class InstallmentService
{
    public const LINKED_TYPE = 'installment';
    public const CONTRACT_TYPE = 'contract';
    /** Module-registered by Contracts; referenced by key only. */
    public const MANAGE_PERMISSION = 'contracts.edit';
    public const REASON_MAX = 450;
    private const DESCRIPTION_MAX = 255;

    public function __construct(
        private readonly InstallmentRepository $installments,
        private readonly FinanceDocumentLinkRepository $links,
        private readonly ProformaRepository $proformaRows,
        private readonly InvoiceRepository $invoiceRows,
        private readonly CreditNoteRepository $creditNotes,
        private readonly ProformaService $proformas,
        private readonly InvoiceService $invoices,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
    ) {
    }

    /**
     * The contract's status as Contracts itself reports it
     * (ContractService::effectiveStatus()): an `active` contract whose end
     * date has passed is `expired`. Kept identical; a test pins the two
     * together.
     */
    public static function contractStatus(array $contract, ?string $today = null): string
    {
        $today ??= \App\Infrastructure\Time\OrganizationTimezone::shared()->today(); // identical to ContractService::effectiveStatus()

        if (($contract['status'] ?? '') === 'active' && !empty($contract['end_date']) && $contract['end_date'] < $today) {
            return 'expired';
        }

        return (string) ($contract['status'] ?? '');
    }

    /** The contract row if this user may view it, else null. */
    public function contract(array $actingUser, int $contractId): ?array
    {
        if ($contractId <= 0 || $this->recordTypes === null) {
            return null;
        }

        return $this->recordTypes->find(self::CONTRACT_TYPE, $contractId, $actingUser);
    }

    public function contractUrl(int $contractId): ?string
    {
        return $this->recordTypes?->get(self::CONTRACT_TYPE)?->showUrl($contractId);
    }

    /** Where to send the user back for an installment: its contract page, only if they may view that contract. */
    public function returnUrl(array $actingUser, int $installmentId): ?string
    {
        $installment = $installmentId > 0 ? $this->installments->findById($installmentId) : null;
        if ($installment === null || $installment['linked_type'] !== self::CONTRACT_TYPE
            || $this->contract($actingUser, (int) $installment['linked_id']) === null) {
            return null;
        }

        return $this->contractUrl((int) $installment['linked_id']);
    }

    public function canManage(array $actingUser, array $contract): bool
    {
        return $this->authorization->can($actingUser, self::MANAGE_PERMISSION, $contract);
    }

    // ---- Writing the schedule ------------------------------------------

    public function create(array $actingUser, int $contractId, array $input): array
    {
        $contract = $this->manageableContract($actingUser, $contractId);
        if (self::contractStatus($contract) !== 'active') {
            throw new ValidationException(['contract' => [t('finance.installment.error.contract_not_active')]]);
        }

        [$dueDate, $amount, $description] = $this->validatedFields($input);
        $requested = (int) ($input['currency_id'] ?? 0);

        return $this->installments->atomically(function () use ($actingUser, $contractId, $contract, $dueDate, $amount, $description, $requested): array {
            $currencyId = $this->scheduleCurrency($contract, $contractId, $requested);
            $this->assertAmountPrecision($amount, $currencyId);
            $sequence = $this->installments->nextSequence(self::CONTRACT_TYPE, $contractId);

            try {
                $id = $this->installments->create([
                    'linked_type' => self::CONTRACT_TYPE,
                    'linked_id' => $contractId,
                    'sequence' => $sequence,
                    'due_date' => $dueDate,
                    'amount' => $amount,
                    'currency_id' => $currencyId,
                    'description' => $description,
                    'created_by_user_id' => (int) $actingUser['id'],
                ]);
            } catch (\PDOException $e) {
                // A concurrent "add" took this number (UNIQUE sequence): nothing was written.
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ValidationException(['installment' => [t('finance.installment.error.schedule_changed')]]);
                }
                throw $e;
            }

            $this->timeline->record(self::CONTRACT_TYPE, $contractId, 'installment_created', 'Installment #' . $sequence . ' added to the payment schedule', (int) $actingUser['id']);

            return $this->installments->findById($id);
        });
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        [$installment, $contract] = $this->manageableInstallment($actingUser, $id);
        if (self::contractStatus($contract) !== 'active') {
            throw new ValidationException(['contract' => [t('finance.installment.error.contract_not_active')]]);
        }
        [$dueDate, $amount, $description] = $this->validatedFields($input);
        $this->assertAmountPrecision($amount, (int) $installment['currency_id']);

        return $this->installments->atomically(function () use ($actingUser, $id, $installment, $dueDate, $amount, $description): array {
            $locked = $this->lockedOpen($id);
            $this->installments->updateInstallment($id, [
                'due_date' => $dueDate,
                'amount' => $amount,
                'description' => $description,
                'updated_by_user_id' => (int) $actingUser['id'],
            ]);
            $this->timeline->record(self::CONTRACT_TYPE, (int) $installment['linked_id'], 'installment_updated', 'Installment #' . $locked['sequence'] . ' updated', (int) $actingUser['id']);

            return $this->installments->findById($id);
        });
    }

    public function void(array $actingUser, int $id, string $reason): array
    {
        [$installment] = $this->manageableInstallment($actingUser, $id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => [t('finance.installment.error.reason_required')]]);
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new ValidationException(['reason' => [t('finance.installment.error.reason_too_long')]]);
        }

        return $this->installments->atomically(function () use ($actingUser, $id, $installment, $reason): array {
            $locked = $this->lockedOpen($id);
            $this->installments->updateInstallment($id, [
                'voided_at' => date('Y-m-d H:i:s'),
                'voided_by_user_id' => (int) $actingUser['id'],
                'void_reason' => $reason,
                'updated_by_user_id' => (int) $actingUser['id'],
            ]);
            $this->timeline->record(self::CONTRACT_TYPE, (int) $installment['linked_id'], 'installment_voided', 'Installment #' . $locked['sequence'] . ' voided: ' . $reason, (int) $actingUser['id']);

            return $this->installments->findById($id);
        });
    }

    /**
     * Data for the edit / void page. Same access as the actions: installment
     * of this organization + contract viewable (404) + contracts.edit on it (403).
     */
    public function forEdit(array $actingUser, int $id): array
    {
        [$installment, $contract] = $this->manageableInstallment($actingUser, $id);
        $void = $installment['voided_at'] !== null;
        $hasDocument = !$void && $this->activeDocument($id) !== null;
        $active = self::contractStatus($contract) === 'active';

        $blocked = match (true) {
            $void => 'finance.installment.error.voided',
            $hasDocument => 'finance.installment.error.has_active_document',
            !$active => 'finance.installment.error.contract_not_active',
            default => null,
        };

        return [
            'installment' => $installment,
            'label' => $this->label($installment),
            'contractTitle' => trim((string) ($contract['title'] ?? '')) !== '' ? (string) $contract['title'] : '#' . $contract['id'],
            'contractUrl' => $this->contractUrl((int) $contract['id']),
            'canEdit' => !$void && !$hasDocument && $active,
            'canVoid' => !$void && !$hasDocument,
            'blockedReason' => $blocked !== null ? t($blocked) : null,
        ];
    }

    // ---- Documents from an installment -----------------------------------

    /**
     * The installment and its contract when a NEW document may be made from
     * it by this user: installment of this organization, contract viewable,
     * contract active or expired, installment not void, no active document.
     * Unavailable → NotFoundException; not allowed now → ValidationException.
     *
     * @return array{0: array, 1: array}
     */
    public function billable(array $actingUser, int $id): array
    {
        $installment = $id > 0 ? $this->installments->findById($id) : null;
        $contract = $installment !== null && $installment['linked_type'] === self::CONTRACT_TYPE
            ? $this->contract($actingUser, (int) $installment['linked_id'])
            : null;
        if ($installment === null || $contract === null) {
            throw new NotFoundException('Installment not found.');
        }
        if (!in_array(self::contractStatus($contract), ['active', 'expired'], true)) {
            throw new ValidationException(['source' => [t('finance.installment.error.contract_not_billable')]]);
        }
        $this->assertOpen($installment);

        return [$installment, $contract];
    }

    /**
     * Inside the caller's transaction, FIRST statement: locks the installment
     * row and re-checks it is still open (not void, no active document), so
     * two requests can never both attach a document to it.
     */
    public function lockForDocument(int $id): array
    {
        $locked = $this->installments->lockForUpdate($id);
        if ($locked === null) {
            throw new ValidationException(['source' => [t('finance.installment.error.unavailable')]]);
        }
        $this->assertOpen($locked);

        return $locked;
    }

    /**
     * The one active document linked to an installment, read without any
     * user filter (it is a uniqueness check, not a display).
     *
     * @return array{type: string, id: int, row: array}|null
     */
    public function activeDocument(int $installmentId): ?array
    {
        foreach ($this->links->documentIdsLinkedTo('invoice', self::LINKED_TYPE, $installmentId) as $docId) {
            $row = $this->invoiceRows->findById($docId);
            if ($row !== null && $row['status'] !== 'cancelled') {
                return ['type' => 'invoice', 'id' => $docId, 'row' => $row];
            }
        }
        foreach ($this->links->documentIdsLinkedTo('proforma', self::LINKED_TYPE, $installmentId) as $docId) {
            $row = $this->proformaRows->findById($docId);
            if ($row !== null && $row['converted_invoice_id'] === null) {
                return ['type' => 'proforma', 'id' => $docId, 'row' => $row];
            }
        }

        return null;
    }

    public function label(array $installment): string
    {
        return t('finance.installment.label', ['sequence' => (int) $installment['sequence']]);
    }

    // ---- Reading the schedule (contract page) -----------------------------

    /**
     * The schedule as this user may see it (caller has checked invoices.view
     * and the contract via find()). A linked document is resolved through its
     * OWN finder (ProformaService / InvoiceService::find: permission + record
     * scope); when the user may not open it, nothing about it is exposed —
     * no number, amount, currency, status, overdue or review flag.
     * Credit-note amounts are never shown here (Patch B); only the derived
     * status uses them, as the invoice page's outstanding does.
     *
     * @param \Closure(string): bool $can blanket permission check for this user
     */
    public function scheduleFor(array $actingUser, array $contract, \Closure $can): array
    {
        $contractId = (int) $contract['id'];
        $status = self::contractStatus($contract);
        $manage = $this->canManage($actingUser, $contract);
        $rows = [];
        $totals = [];

        foreach ($this->installments->forLinked(self::CONTRACT_TYPE, $contractId) as $i) {
            $void = $i['voided_at'] !== null;
            $active = $void ? null : $this->activeDocument((int) $i['id']);
            $open = !$void && $active === null;
            $document = $active !== null ? $this->visibleDocument($actingUser, $active) : null;
            $restricted = $active !== null && $document === null;
            $derived = $void ? 'void' : ($restricted ? null : $this->derivedStatus($active));

            $review = null;
            if ($document !== null) {
                if ((int) $document['currency_id'] !== (int) $i['currency_id']) {
                    $review = 'currency';
                } elseif (bccomp((string) $document['amount'], (string) $i['amount'], 18) !== 0) {
                    $review = 'amount';
                }
            }

            if (!$void) {
                $cur = (int) $i['currency_id'];
                $totals[$cur] = bcadd($totals[$cur] ?? '0', (string) $i['amount'], 18);
            }

            $rows[] = [
                'id' => (int) $i['id'],
                'sequence' => (int) $i['sequence'],
                'due_date' => (string) $i['due_date'],
                'amount' => (string) $i['amount'],
                'currency_id' => (int) $i['currency_id'],
                'description' => (string) ($i['description'] ?? ''),
                'void' => $void,
                'void_reason' => $void ? (string) $i['void_reason'] : null,
                'status' => $derived,
                'overdue' => $derived !== null && !in_array($derived, ['paid', 'void'], true) && $i['due_date'] < \App\Infrastructure\Time\OrganizationTimezone::shared()->today(),
                'document' => $document,
                'restricted' => $restricted,
                'review' => $review,
                'can_edit' => $manage && $open && $status === 'active',
                'can_void' => $manage && $open,
                'can_create_proforma' => $open && in_array($status, ['active', 'expired'], true) && $can('quotes.create'),
                'can_create_invoice' => $open && in_array($status, ['active', 'expired'], true) && $can('invoices.create'),
            ];
        }

        $fixed = !empty($contract['currency_id']) ? (int) $contract['currency_id'] : ($this->installments->activeCurrencies(self::CONTRACT_TYPE, $contractId)[0] ?? null);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'can_add' => $manage && $status === 'active',
            'currency_id' => $fixed,
            'currencies' => $fixed === null ? $this->currencies->all() : [], // active ones only
        ];
    }

    // ---- Internals ---------------------------------------------------------

    private function manageableContract(array $actingUser, int $contractId): array
    {
        $contract = $this->contract($actingUser, $contractId);
        if ($contract === null) {
            throw new NotFoundException('Contract not found.');
        }
        if (!$this->canManage($actingUser, $contract)) {
            throw new AuthorizationException();
        }

        return $contract;
    }

    /** @return array{0: array, 1: array} */
    private function manageableInstallment(array $actingUser, int $id): array
    {
        $installment = $id > 0 ? $this->installments->findById($id) : null;
        if ($installment === null || $installment['linked_type'] !== self::CONTRACT_TYPE) {
            throw new NotFoundException('Installment not found.');
        }

        return [$installment, $this->manageableContract($actingUser, (int) $installment['linked_id'])];
    }

    /** Row lock + "still open" re-check; the first statement of the caller's transaction. */
    private function lockedOpen(int $id): array
    {
        $locked = $this->installments->lockForUpdate($id);
        if ($locked === null) {
            throw new NotFoundException('Installment not found.');
        }
        $this->assertOpen($locked);

        return $locked;
    }

    private function assertOpen(array $installment): void
    {
        if ($installment['voided_at'] !== null) {
            throw new ValidationException(['installment' => [t('finance.installment.error.voided')]]);
        }
        if ($this->activeDocument((int) $installment['id']) !== null) {
            throw new ValidationException(['installment' => [t('finance.installment.error.has_active_document')]]);
        }
    }

    /**
     * Contract currency when it has one; otherwise the currency of the
     * schedule's existing (non-void) installments; otherwise the requested
     * one (must exist and be active). A different requested currency is refused.
     */
    /** Stage 5.2 (L2): an installment amount has at most its currency's money precision (IRR 3) — never truncated. */
    private function assertAmountPrecision(string $amount, int $currencyId): void
    {
        $decimals = Money::decimalsFor($currencyId);
        if (Money::fractionDigits($amount) > $decimals) {
            throw new ValidationException(['amount' => [t('finance.error.amount_precision', ['max' => $decimals])]]);
        }
    }

    private function scheduleCurrency(array $contract, int $contractId, int $requested): int
    {
        $fixed = !empty($contract['currency_id'])
            ? (int) $contract['currency_id']
            : ($this->installments->activeCurrencies(self::CONTRACT_TYPE, $contractId)[0] ?? null);

        if ($fixed !== null && $requested > 0 && $requested !== $fixed) {
            throw new ValidationException(['currency_id' => [t('finance.installment.error.currency_mismatch')]]);
        }
        $currencyId = $fixed ?? $requested;
        $currency = $currencyId > 0 ? $this->currencies->findById($currencyId) : null;
        if ($currency === null || empty($currency['is_active'])) {
            throw new ValidationException(['currency_id' => [t('finance.error.invalid_currency')]]);
        }

        return $currencyId;
    }

    /** @return array{0: string, 1: string, 2: ?string} due date, amount, description */
    private function validatedFields(array $input): array
    {
        $errors = [];

        $dueDate = trim((string) ($input['due_date'] ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dueDate, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['due_date'][] = t('finance.installment.error.invalid_due_date');
        }

        $amount = str_replace([',', ' '], '', trim((string) ($input['amount'] ?? '')));
        if (preg_match('/^\d{1,20}(\.\d{1,18})?$/', $amount) !== 1 || bccomp($amount, '0', 18) <= 0) {
            $errors['amount'][] = t('finance.installment.error.invalid_amount');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'][] = t('finance.installment.error.description_too_long');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$dueDate, $amount, $description !== '' ? $description : null];
    }

    /** The document as this user may see it, or null when they may not open it. */
    private function visibleDocument(array $actingUser, array $active): ?array
    {
        try {
            $row = $active['type'] === 'invoice'
                ? $this->invoices->find($actingUser, $active['id'])
                : $this->proformas->find($actingUser, $active['id']);
        } catch (AuthorizationException | NotFoundException) {
            return null;
        }

        $number = ($row['custom_number'] ?? '') !== '' ? $row['custom_number']
            : ($active['type'] === 'invoice' && !empty($row['invoice_number']) ? $row['invoice_number'] : '#' . $row['id']);

        return [
            'type' => $active['type'],
            'id' => (int) $row['id'],
            'number' => (string) $number,
            'status' => (string) $row['status'],
            'amount' => (string) $row['grand_total'],
            'currency_id' => (int) $row['currency_id'],
        ];
    }

    /** Derived from the active document with the invoice page's own figures (grand − credited − paid). */
    private function derivedStatus(?array $active): string
    {
        if ($active === null || $active['type'] === 'proforma' || $active['row']['status'] !== 'issued') {
            return 'unpaid';
        }

        $invoice = $active['row'];
        $credited = $this->creditNotes->totalCreditedForInvoice((int) $invoice['id']);
        $outstanding = bcsub(bcsub((string) $invoice['grand_total'], $credited, 18), (string) $invoice['paid_total'], 18);

        if (bccomp($outstanding, '0', 18) <= 0) {
            return 'paid';
        }

        return bccomp((string) $invoice['paid_total'], '0', 18) > 0 ? 'partially_paid' : 'issued';
    }
}
