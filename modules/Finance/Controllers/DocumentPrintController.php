<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Pdf\PdfRenderer;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\DocumentPrintService;

/**
 * Print view (A4 page in the browser) and real PDF download for
 * invoices and proformas. Both render the SAME document template
 * (finance/print/document), so the PDF matches what's on screen; only
 * the logo source differs (an authenticated URL on screen, the local
 * file for mPDF).
 */
final class DocumentPrintController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly DocumentPrintService $documents,
        private readonly PdfRenderer $pdf,
    ) {
        parent::__construct($templates);
    }

    public function invoicePrint(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->page($this->documents->invoice(CurrentUser::user(), $id), '/finance/invoices/' . $id);
    }

    public function invoicePdf(Request $request): Response
    {
        return $this->download($this->documents->invoice(CurrentUser::user(), (int) $request->attribute('id')));
    }

    public function proformaPrint(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->page($this->documents->proforma(CurrentUser::user(), $id), '/finance/proformas/' . $id);
    }

    public function proformaPdf(Request $request): Response
    {
        return $this->download($this->documents->proforma(CurrentUser::user(), (int) $request->attribute('id')));
    }

    private function page(array $doc, string $backUrl): Response
    {
        $logo = $doc['seller']['logo_path'] !== null ? '/org/logo?v=' . substr(md5($doc['seller']['logo_path']), 0, 12) : null;

        return $this->view('finance/print/page', [
            'doc' => $doc,
            'documentHtml' => $this->document($doc, $logo),
            'stylesHtml' => $this->templates->render('finance/print/styles', ['forPdf' => false]),
            'backUrl' => $backUrl,
            'pdfUrl' => $backUrl . '/pdf',
        ]);
    }

    private function download(array $doc): Response
    {
        $translator = Locale::get();
        $html = $this->templates->render('finance/print/styles', ['forPdf' => true])
            . $this->document($doc, $doc['seller']['logo_path']);

        $bytes = $this->pdf->render(
            $html,
            $translator !== null ? $translator->direction() : 'ltr',
            (string) $doc['number'],
            $translator !== null ? $translator->locale() : 'en',
        );
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $doc['number']) . '.pdf';

        return Response::html($bytes)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function document(array $doc, ?string $logoSrc): string
    {
        $translator = Locale::get();

        return $this->templates->render('finance/print/document', [
            'doc' => $doc,
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
            'logoSrc' => $logoSrc,
        ]);
    }
}
