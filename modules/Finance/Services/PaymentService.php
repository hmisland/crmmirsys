<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\PaymentRepository;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly AuthorizationEngine $authorization,
        private readonly FinancialPeriodService $financialPeriods,
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

        $amount = (string) ($input['amount'] ?? '');
        if (!is_numeric($amount) || bccomp($amount, '0', 18) <= 0) {
            throw new ValidationException(['amount' => ['Amount must be a positive number.']]);
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

        return $this->invoices->transaction(function () use ($actingUser, $invoiceId, $amount, $input, $paymentDate) {
            // Row-locked re-read inside the transaction — the
            // outstanding-balance check below must never run against
            // a stale paid_total if two payments are recorded for
            // the same invoice at nearly the same moment.
            $lockedInvoice = $this->invoices->lockForUpdate($invoiceId);
            $totalCredited = $this->creditNotes->totalCreditedForInvoice($invoiceId);
            $effectiveTotal = bcsub($lockedInvoice['grand_total'], $totalCredited, 8);

            $outstanding = bcsub($effectiveTotal, $lockedInvoice['paid_total'], 8);
            if (bccomp($amount, $outstanding, 8) > 0) {
                throw new ValidationException(['amount' => ['This amount is more than the outstanding balance (' . $outstanding . ').']]);
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

            $newPaidTotal = bcadd($lockedInvoice['paid_total'], $amount, 8);
            $this->invoices->updateInvoice($invoiceId, ['paid_total' => $newPaidTotal]);

            return ['payment_id' => $paymentId, 'invoice_id' => $invoiceId];
        });
    }

    /** 'unpaid' | 'partial' | 'paid' — derived from paid_total vs (grand_total - total_credited) at read time, never stored, so it can never drift out of sync. */
    public static function paymentStatus(array $invoice, string $totalCredited = '0'): string
    {
        $effectiveTotal = bcsub($invoice['grand_total'], $totalCredited, 8);

        if (bccomp($invoice['paid_total'], '0', 8) <= 0) {
            return 'unpaid';
        }

        if (bccomp($invoice['paid_total'], $effectiveTotal, 8) >= 0) {
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
