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

        $invoiceSummary = $this->invoices->summaryForPeriod($startDate, $endDate);
        $totalCredited = $this->creditNotes->totalForPeriod($startDate, $endDate);
        $netRevenue = bcsub($invoiceSummary['total_revenue'], $totalCredited, 8);
        $outstanding = bcsub($netRevenue, $invoiceSummary['total_paid'], 8);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'invoice_count' => (int) $invoiceSummary['invoice_count'],
            'gross_revenue' => $invoiceSummary['total_revenue'],
            'total_credited' => $totalCredited,
            'net_revenue' => $netRevenue,
            'total_tax' => $invoiceSummary['total_tax'],
            'total_paid' => $invoiceSummary['total_paid'],
            'outstanding' => $outstanding,
        ];
    }
}
