<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ContactImportExportService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class ContactImportController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly ContactImportExportService $importExport)
    {
        parent::__construct($templates);
    }

    public function export(Request $request): Response
    {
        $csv = $this->importExport->exportCsv(CurrentUser::user(), ['search' => (string) $request->query('q', '')]);

        return Response::html($csv)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="contacts-' . date('Y-m-d') . '.csv"');
    }

    public function showImport(Request $request): Response
    {
        return $this->viewWithLayout('crm/contacts/import', 'layout', ['error' => null]);
    }

    public function preview(Request $request): Response
    {
        $file = $request->file('file');

        if ($file === null) {
            return $this->viewWithLayout('crm/contacts/import', 'layout', ['error' => 'Choose a CSV file first.']);
        }

        try {
            $result = $this->importExport->parsePreview(CurrentUser::user(), $file);
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/contacts/import', 'layout', [
                'error' => array_values($e->errors)[0][0] ?? 'Could not read that file.',
            ]);
        }

        return $this->viewWithLayout('crm/contacts/import-preview', 'layout', [
            'rows' => $result['rows'],
            'customFieldDefs' => $result['customFieldDefs'],
        ]);
    }

    public function commit(Request $request): Response
    {
        $rows = (array) $request->input('rows', []);
        $result = $this->importExport->commit(CurrentUser::user(), $rows);

        return $this->viewWithLayout('crm/contacts/import-result', 'layout', ['result' => $result]);
    }
}
