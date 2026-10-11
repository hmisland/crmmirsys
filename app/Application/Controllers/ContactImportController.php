<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\ContactCategoryService;
use App\Application\Services\ContactImportExportService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

final class ContactImportController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ContactImportExportService $importExport,
        private readonly ContactCategoryService $categories,
        private readonly CompanyRepository $companies,
    ) {
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
        return $this->importPage(null);
    }

    /**
     * Two entry points into the same review screen: a fresh upload
     * (file present — stored, then auto-mapped), or "update preview"
     * after the user changed the column mapping (token + mapping).
     */
    public function preview(Request $request): Response
    {
        $user = CurrentUser::user();
        $file = $request->file('file');

        try {
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $stored = $this->importExport->upload($user, $file);
                $token = $stored['token'];
                $fileName = $stored['file_name'];
                $mapping = null;
            } else {
                $token = (string) $request->input('token', '');
                $fileName = (string) $request->input('file_name', '');
                $mapping = (array) $request->input('mapping', []);

                if ($token === '') {
                    return $this->importPage('import.error.choose_file');
                }
            }

            $preview = $this->importExport->preview($user, $token, $mapping);
        } catch (ValidationException $e) {
            return $this->importPage(array_values($e->errors)[0][0] ?? 'import.error.unreadable');
        }

        return $this->viewWithLayout('crm/contacts/import-preview', 'layout', $preview + [
            'token' => $token,
            'fileName' => $fileName,
            'targets' => ContactImportExportService::TARGETS,
            'newCustom' => ContactImportExportService::NEW_CUSTOM,
            'rights' => $this->importExport->creationRights($user),
            'categories' => $this->categories->all(),
            'companies' => $this->companies->all(),
            'selection' => [
                'category_id' => (string) $request->input('category_id', ''),
                'new_category_name' => (string) $request->input('new_category_name', ''),
                'company_id' => (string) $request->input('company_id', ''),
                'new_company_name' => (string) $request->input('new_company_name', ''),
            ],
        ]);
    }

    public function commit(Request $request): Response
    {
        $user = CurrentUser::user();

        try {
            $result = $this->importExport->commit($user, $request->all());
        } catch (ValidationException $e) {
            return $this->importPage(array_values($e->errors)[0][0] ?? 'import.error.unreadable');
        }

        return $this->viewWithLayout('crm/contacts/import-result', 'layout', [
            'result' => $result,
            'latestBatch' => $this->importExport->latestBatch($user),
        ]);
    }

    public function rollback(Request $request): Response
    {
        $user = CurrentUser::user();

        try {
            $report = $this->importExport->rollback($user, (int) $request->input('batch_id', 0));
        } catch (ValidationException $e) {
            return $this->importPage(array_values($e->errors)[0][0] ?? 'import.error.unreadable');
        }

        return $this->importPage(null, $report);
    }

    private function importPage(?string $errorKey, ?array $rollbackReport = null): Response
    {
        return $this->viewWithLayout('crm/contacts/import', 'layout', [
            'error' => $errorKey !== null ? t($errorKey) : null,
            'latestBatch' => $this->importExport->latestBatch(CurrentUser::user()),
            'rollbackReport' => $rollbackReport,
        ], $errorKey !== null ? 422 : 200);
    }
}
