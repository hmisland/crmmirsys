<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\CreditNoteRepository;
use Modules\Finance\InvoiceRepository;

final class ReportService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function summary(array $actingUser, string $startDate, string $endDate): array
    {
        if (!$this->authorization->can($actingUser, Permission::FINANCIAL_REPORTS_VIEW)) {
            throw new AuthorizationException();
        }

        // Remediation P1 (decision D1 = A): the invoices ISSUED in the period, per
        // currency — never one total across currencies (no FX). Their credit notes
        // (whatever the credit-note date) and payments so far are those invoices' own,
        // so "outstanding" is what these invoices still owe today: the same
        // grand − credited − paid rule as the invoice page — not a receivables
        // balance at the period end (the page says so).
        $currencies = [];
        $count = 0;
        foreach ($this->invoices->periodPerformanceByCurrency($startDate, $endDate) as $row) {
            $net = bcsub((string) $row['total_revenue'], (string) $row['total_credited'], 18);
            $currencies[(int) $row['currency_id']] = [
                'currency_code' => (string) ($row['currency_code'] ?? ''),
                'invoice_count' => (int) $row['invoice_count'],
                'gross_revenue' => (string) $row['total_revenue'],
                'total_credited' => (string) $row['total_credited'],
                'net_revenue' => $net,
                'total_tax' => (string) $row['total_tax'],
                'total_paid' => (string) $row['total_paid'],
                'outstanding' => bcsub($net, (string) $row['total_paid'], 18),
            ];
            $count += (int) $row['invoice_count'];
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'invoice_count' => $count, // a count, so adding across currencies is meaningful
            'currencies' => $currencies,
        ];
    }
}
