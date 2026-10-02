<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\CreditNoteNumberSeriesRepository;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\InvoiceRepository;

final class CreditNoteService
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNoteNumberSeriesRepository $numberSeries,
        private readonly InvoiceRepository $invoices,
        private readonly AuthorizationEngine $authorization,
        private readonly FinancialPeriodService $financialPeriods,
    ) {
    }

    public function listFor(int $invoiceId): array
    {
        return $this->creditNotes->forInvoice($invoiceId);
    }

    public function totalCreditedFor(int $invoiceId): string
    {
        return $this->creditNotes->totalCreditedForInvoice($invoiceId);
    }

    /**
     * The invoice itself is never touched — grand_total stays frozen
     * forever. Only a new credit_notes row is written; "what's
     * really owed" is always derived at read time as
     * grand_total - total_credited (independent of paid_total —
     * crediting a fully-paid invoice is a legitimate "now a refund
     * is owed" situation, not an error; an actual money-back Refund
     * workflow is separate, future work, not built this phase).
     */
    public function issue(array $actingUser, int $invoiceId, array $input): array
    {
        $invoice = $this->requireInvoice($invoiceId);

        if (!$this->authorization->can($actingUser, Permission::CREDIT_NOTES_CREATE, $invoice)) {
            throw new AuthorizationException();
        }

        if ($invoice['status'] !== 'issued') {
            throw new ValidationException(['invoice' => ['A credit note can only be issued against an issued invoice.']]);
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException(['reason' => ['A reason is required for every credit note.']]);
        }

        $amount = (string) ($input['amount'] ?? '');
        if (!is_numeric($amount) || bccomp($amount, '0', 18) <= 0) {
            throw new ValidationException(['amount' => ['Amount must be a positive number.']]);
        }

        $issueDate = (string) ($input['issue_date'] ?? date('Y-m-d'));
        if ($this->financialPeriods->isDateLocked($issueDate)) {
            throw new ValidationException(['issue_date' => ['This date falls inside a closed financial period.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];

        return $this->invoices->transaction(function () use ($organizationId, $actingUser, $invoiceId, $amount, $reason, $issueDate) {
            // Re-check the creditable amount inside the transaction,
            // against a fresh read — the same "never trust a
            // pre-transaction read" discipline as recording a
            // payment, so two credit notes issued in quick
            // succession can't both pass a stale check.
            $lockedInvoice = $this->invoices->lockForUpdate($invoiceId);
            $alreadyCredited = $this->creditNotes->totalCreditedForInvoice($invoiceId);
            $creditable = bcsub($lockedInvoice['grand_total'], $alreadyCredited, 8);

            if (bccomp($amount, $creditable, 8) > 0) {
                throw new ValidationException(['amount' => ['This amount is more than what can still be credited (' . $creditable . ') — a credit note cannot exceed the invoice\'s own total, minus any already credited.']]);
            }

            $series = $this->numberSeries->lockForUpdate($organizationId);
            if ($series === null) {
                $series = $this->numberSeries->createDefault($organizationId);
            }

            $sequence = (int) $series['next_sequence'];
            $creditNoteNumber = $series['prefix'] . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
            $this->numberSeries->advance($organizationId, $sequence + 1);

            $id = $this->creditNotes->create([
                'invoice_id' => $invoiceId,
                'credit_note_number' => $creditNoteNumber,
                'issue_date' => $issueDate,
                'reason' => $reason,
                'amount' => $amount,
                'issued_by_user_id' => $actingUser['id'],
            ]);

            return ['id' => $id, 'credit_note_number' => $creditNoteNumber];
        });
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
