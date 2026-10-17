<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\AmountInput;
use Modules\Finance\CreditNoteNumberSeriesRepository;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\DuplicateSubmission;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\LockConflict;
use Modules\Finance\Money;
use Modules\Finance\SubmissionKeyRepository;

use function App\Infrastructure\Theme\t;

final class CreditNoteService
{
    public function __construct(
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNoteNumberSeriesRepository $numberSeries,
        private readonly InvoiceRepository $invoices,
        private readonly AuthorizationEngine $authorization,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly SubmissionKeyRepository $submissionKeys = new SubmissionKeyRepository(),
        // Stage 5.2 (L4): the audit event, written inside the same transaction as the record.
        private readonly ?TimelineService $timeline = null,
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

        // Remediation P3: "1,000" accepted, "1e3" / "abc" / negatives refused (422, not 500).
        $amount = AmountInput::normalize($input['amount'] ?? '') ?? '';
        if ($amount === '' || bccomp($amount, '0', 18) <= 0) {
            throw new ValidationException(['amount' => ['Amount must be a positive number.']]);
        }
        // Stage 5.2 (L2): at most the invoice currency's money precision (IRR 3) — never truncated.
        $decimals = Money::decimalsFor((int) $invoice['currency_id']);
        if (Money::fractionDigits($amount) > $decimals) {
            throw new ValidationException(['amount' => [t('finance.error.amount_precision', ['max' => $decimals])]]);
        }

        $issueDate = (string) ($input['issue_date'] ?? \App\Infrastructure\Time\OrganizationTimezone::shared()->today());
        if ($this->financialPeriods->isDateLocked($issueDate)) {
            throw new ValidationException(['issue_date' => ['This date falls inside a closed financial period.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];

        // Stage 5.2 (L1): submission key first (lock order key → document → period → series).
        $key = SubmissionKeyRepository::keyFrom($input);
        try {
            return LockConflict::guard(fn () => $this->invoices->transaction(function () use ($organizationId, $actingUser, $invoiceId, $amount, $reason, $issueDate, $key) {
                if ($key !== null && !$this->submissionKeys->claim(SubmissionKeyRepository::SCOPE_CREDIT_NOTE, $key, (int) $actingUser['id'])) {
                    throw new DuplicateSubmission();
                }
                // Re-check the creditable amount inside the transaction,
                // against a fresh read — the same "never trust a
                // pre-transaction read" discipline as recording a
                // payment, so two credit notes issued in quick
                // succession can't both pass a stale check.
                $lockedInvoice = $this->invoices->lockForUpdate($invoiceId);
                // Stage 5.2 (L5): lock order document → period (shared) → number series, the
                // same in every financial write; close() only ever takes the period row.
                $this->financialPeriods->assertOpenForWrite($issueDate, 'issue_date', 'This date falls inside a closed financial period.');
                $alreadyCredited = $this->creditNotes->totalCreditedForInvoice($invoiceId);
                $creditable = bcsub($lockedInvoice['grand_total'], $alreadyCredited, Money::SCALE);

                if (bccomp($amount, $creditable, Money::SCALE) > 0) {
                    throw new ValidationException(['amount' => ['This amount is more than what can still be credited (' . rtrim(rtrim($creditable, '0'), '.') . ') — a credit note cannot exceed the invoice\'s own total, minus any already credited.']]);
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

                if ($key !== null) {
                    $this->submissionKeys->attach(SubmissionKeyRepository::SCOPE_CREDIT_NOTE, $key, $id);
                }
                // Stage 5.2 (L4): same transaction as the credit note (and its number).
                $this->timeline?->record(
                    'invoice',
                    $invoiceId,
                    'credit_note_issued',
                    'Credit note ' . $creditNoteNumber . ': ' . Money::plain($amount) . ', dated ' . $issueDate
                        . ' — credited ' . Money::plain($alreadyCredited) . ' → ' . Money::plain(bcadd($alreadyCredited, $amount, Money::SCALE)),
                    (int) $actingUser['id']
                );

                return ['id' => $id, 'credit_note_number' => $creditNoteNumber];
            }));
        } catch (DuplicateSubmission) {
            $original = $this->submissionKeys->original(SubmissionKeyRepository::SCOPE_CREDIT_NOTE, (string) $key, (int) $actingUser['id'])
                ?? throw new ValidationException(['submission_key' => [t('finance.error.already_submitted')]]);
            $row = array_values(array_filter($this->creditNotes->forInvoice($invoiceId), fn (array $c) => (int) $c['id'] === $original))[0] ?? null;

            return ['id' => $original, 'credit_note_number' => $row['credit_note_number'] ?? null, 'duplicate' => true];
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
