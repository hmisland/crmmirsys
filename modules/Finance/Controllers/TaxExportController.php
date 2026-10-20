<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\TaxInvoiceExport;

use function App\Infrastructure\Theme\t;

/**
 * Invoices → CSV for the tax system (invoices.view): pick issued invoices,
 * see what may not match the tax system, and download the file anyway.
 */
final class TaxExportController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly InvoiceService $invoices,
        private readonly TaxInvoiceExport $export,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => 'issued',
            'search' => trim((string) $request->query('search', '')),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        return $this->viewWithLayout('finance/invoices/tax-export', 'layout', [
            'invoices' => $this->invoices->listPage(CurrentUser::user(), array_filter($filters, fn ($v) => $v !== ''), 200, 0)['rows'],
            'filters' => $filters,
        ]);
    }

    public function download(Request $request): Response
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->query('ids', []))));
        if ($ids === []) {
            Session::setFlash('error', t('tax_export.none_selected'));

            return $this->redirect('/finance/invoices/tax-export');
        }
        $result = $this->export->build(CurrentUser::user(), $ids);

        if ($result['rows'] === [] || ($result['problems'] !== [] && $request->query('confirm') !== '1')) {
            return $this->viewWithLayout('finance/invoices/tax-export-check', 'layout', [
                'problems' => $result['problems'],
                'ids' => $ids,
                'canBuild' => $result['rows'] !== [],
            ]);
        }

        return Response::html(TaxInvoiceExport::csv($result['rows']))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tax-invoices-' . date('Ymd-His') . '.csv"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
