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
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\DuplicateSubmission;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\LockConflict;
use Modules\Finance\Money;
use Modules\Finance\PaymentRepository;
use Modules\Finance\SubmissionKeyRepository;

use function App\Infrastructure\Theme\t;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly AuthorizationEngine $authorization,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly SubmissionKeyRepository $submissionKeys = new SubmissionKeyRepository(),
        // Stage 5.2 (L4): the audit event, written inside the same transaction as the record.
        private readonly ?TimelineService $timeline = null,
    ) {
    }

    /**
     * A payment has no owner_id of its own — its OWN-scope
     * authorization follows its invoice's ownership, the same
     * reasoning Ticket replies/Task comments use for their parent
     * record.
     */
    public function listFor(array $actingUser, int $invoiceId): array
    {
        $invoice = $this->requireInvoice($invoiceId);

        if (!$this->authorization->can($actingUser, Permission::PAYMENTS_VIEW, $invoice)) {
            throw new AuthorizationException();
        }

        return $this->payments->forInvoice($invoiceId);
    }

    public function record(array $actingUser, int $invoiceId, array $input): array
    {
        $invoice = $this->requireInvoice($invoiceId);

        if (!$this->authorization->can($actingUser, Permission::PAYMENTS_CREATE, $invoice)) {
            throw new AuthorizationException();
        }

        if ($invoice['status'] !== 'issued') {
            throw new ValidationException(['invoice' => ['Only an issued invoice can receive a payment — this one is still a draft or has been cancelled.']]);
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

        if (!in_array($input['payment_method'] ?? null, ['cash', 'bank_transfer', 'card', 'other'], true)) {
            throw new ValidationException(['payment_method' => ['Invalid payment method.']]);
        }

        $paymentDate = (string) ($input['payment_date'] ?? '');
        if ($paymentDate === '') {
            throw new ValidationException(['payment_date' => ['Payment date is required.']]);
        }

        if ($this->financialPeriods->isDateLocked($paymentDate)) {
            throw new ValidationException(['payment_date' => ['This date falls inside a closed financial period.']]);
        }

        // Stage 5.2 (L1): submission key first (lock order key → document → period); a repeat
        // of the same submission is answered with the payment the first one recorded.
        $key = SubmissionKeyRepository::keyFrom($input);
        try {
            return LockConflict::guard(fn () => $this->invoices->transaction(function () use ($actingUser, $invoiceId, $amount, $input, $paymentDate, $key) {
                if ($key !== null && !$this->submissionKeys->claim(SubmissionKeyRepository::SCOPE_PAYMENT, $key, (int) $actingUser['id'])) {
                    throw new DuplicateSubmission();
                }
                // Row-locked re-read inside the transaction — the
                // outstanding-balance check below must never run against
                // a stale paid_total if two payments are recorded for
                // the same invoice at nearly the same moment.
                $lockedInvoice = $this->invoices->lockForUpdate($invoiceId);
                // Stage 5.2 (L5): lock order document → period (shared); the period check again,
                // as a locking read — a concurrent close() can't slip between check and commit.
                $this->financialPeriods->assertOpenForWrite($paymentDate, 'payment_date', 'This date falls inside a closed financial period.');
                $totalCredited = $this->creditNotes->totalCreditedForInvoice($invoiceId);
                $effectiveTotal = bcsub($lockedInvoice['grand_total'], $totalCredited, Money::SCALE);

                $outstanding = bcsub($effectiveTotal, $lockedInvoice['paid_total'], Money::SCALE);
                if (bccomp($amount, $outstanding, Money::SCALE) > 0) {
                    throw new ValidationException(['amount' => ['This amount is more than the outstanding balance (' . rtrim(rtrim($outstanding, '0'), '.') . ').']]);
                }

                $paymentId = $this->payments->create([
                    'invoice_id' => $invoiceId,
                    'amount' => $amount,
                    'payment_method' => $input['payment_method'],
                    'payment_reference' => isset($input['payment_reference']) && trim((string) $input['payment_reference']) !== '' ? trim((string) $input['payment_reference']) : null,
                    'payment_date' => $paymentDate,
                    'recorded_by_user_id' => $actingUser['id'],
                    'notes' => isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null,
                ]);

                if ($key !== null) {
                    $this->submissionKeys->attach(SubmissionKeyRepository::SCOPE_PAYMENT, $key, $paymentId);
                }

                $newPaidTotal = bcadd($lockedInvoice['paid_total'], $amount, Money::SCALE);
                $this->invoices->updateInvoice($invoiceId, ['paid_total' => $newPaidTotal]);
                // Stage 5.2 (L4): same transaction — rolled back with the payment, never written for a
                // repeated submission (that returns above via DuplicateSubmission before any write).
                $this->timeline?->record(
                    'invoice',
                    $invoiceId,
                    'payment_recorded',
                    'Payment #' . $paymentId . ': ' . Money::plain($amount) . ' (' . $input['payment_method'] . '), dated ' . $paymentDate
                        . ' — paid ' . Money::plain((string) $lockedInvoice['paid_total']) . ' → ' . Money::plain($newPaidTotal),
                    (int) $actingUser['id']
                );

                return ['payment_id' => $paymentId, 'invoice_id' => $invoiceId];
            }));
        } catch (DuplicateSubmission) {
            $original = $this->submissionKeys->original(SubmissionKeyRepository::SCOPE_PAYMENT, (string) $key, (int) $actingUser['id'])
                ?? throw new ValidationException(['submission_key' => [t('finance.error.already_submitted')]]);

            return ['payment_id' => $original, 'invoice_id' => $invoiceId, 'duplicate' => true];
        }
    }

    /** 'unpaid' | 'partial' | 'paid' — derived from paid_total vs (grand_total - total_credited) at read time, never stored, so it can never drift out of sync. */
    public static function paymentStatus(array $invoice, string $totalCredited = '0'): string
    {
        $effectiveTotal = bcsub($invoice['grand_total'], $totalCredited, Money::SCALE);

        if (bccomp($invoice['paid_total'], '0', Money::SCALE) <= 0) {
            return 'unpaid';
        }

        if (bccomp($invoice['paid_total'], $effectiveTotal, Money::SCALE) >= 0) {
            return 'paid';
        }

        return 'partial';
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
