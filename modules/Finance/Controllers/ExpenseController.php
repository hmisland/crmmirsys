<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\Services\ExpenseService;

use function App\Infrastructure\Theme\t;

/**
 * Expenses (Phase 4). Thin: every rule — scope, project rights, periods,
 * corrections, receipts — lives in ExpenseService. Routes add Auth + Finance
 * module + RbacMiddleware(finance.expenses.view / .manage) + CSRF on POSTs.
 */
final class ExpenseController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ExpenseService $expenses,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
        private readonly ContactRepository $contacts = new ContactRepository(),
        private readonly CompanyRepository $companies = new CompanyRepository(),
        private readonly FinancialProfileRepository $financialProfiles = new FinancialProfileRepository(),
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key, '')) ? (string) $request->query($key) : '';
        $filters = [
            'status' => in_array($request->query('status'), ['recorded', 'void'], true) ? (string) $request->query('status') : '',
            'category' => in_array($request->query('category'), ExpenseService::CATEGORIES, true) ? (string) $request->query('category') : '',
            'currency_id' => (int) $request->query('currency_id', 0) > 0 ? (int) $request->query('currency_id') : '',
            'project_id' => (int) $request->query('project_id', 0) > 0 ? (int) $request->query('project_id') : '',
            'date_from' => $date('from'),
            'date_to' => $date('to'),
        ];

        $requested = Pagination::fromRequest($request, PHP_INT_MAX);
        $page = $this->expenses->listPage(CurrentUser::user(), $filters, $requested->perPage, $requested->offset());
        $pagination = Pagination::fromRequest($request, $page['total']);
        if ($pagination->offset() !== $requested->offset()) {
            $page = $this->expenses->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());
        }

        return $this->viewWithLayout('finance/expenses/index', 'layout', [
            'expenses' => $page['rows'],
            'pagination' => $pagination,
            'filters' => $filters,
            'codes' => $this->codes($page['rows']),
            'currencies' => $this->currencies->all(),
            'canCreate' => $this->expenses->canManageAny(CurrentUser::user()),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        // "New expense" from a project (D-P1): a project this user can't see → 404,
        // no projects.edit → 403, a cancelled one → back to it with the reason.
        if ($request->query('from_project') !== null) {
            $projectId = (int) $request->query('from_project', 0);
            try {
                return $this->form(null, [], $this->expenses->draftFromProject(CurrentUser::user(), $projectId) + ['project_fixed' => '1']);
            } catch (ValidationException $e) {
                Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.expense.error.project_unavailable'));

                return $this->redirect($this->expenses->projectUrl($projectId) ?? '/finance/expenses');
            }
        }

        return $this->form(null, [], ['expense_date' => date('Y-m-d')]);
    }

    /** The project chosen in the form, or the expense's own when editing (for the "current" option / fixed notice). */
    private function selected(array $old, ?array $expense, string $field): int
    {
        return (int) (array_key_exists($field, $old) ? $old[$field] : ($expense[$field] ?? 0));
    }

    public function create(Request $request): Response
    {
        try {
            $expense = $this->expenses->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all());
        }
        Session::setFlash('success', t('finance.expense.flash.created'));

        return $this->redirect('/finance/expenses/' . $expense['id']);
    }

    public function show(Request $request): Response
    {
        $data = $this->expenses->details(CurrentUser::user(), (int) $request->attribute('id'));

        return $this->viewWithLayout('finance/expenses/show', 'layout', $data + [
            'codes' => $this->codes([$data['expense'], ...$data['corrections']]),
            'contacts' => $data['can_correct'] ? $this->contacts->search([]) : [],
            'companies' => $data['can_correct'] ? $this->companies->search([]) : [],
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $data = $this->expenses->details(CurrentUser::user(), $id);
        if (!$data['can_edit']) {
            Session::setFlash('error', t('finance.expense.error.not_editable'));

            return $this->redirect('/finance/expenses/' . $id);
        }

        return $this->form($data['expense'], [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        // Edit form selectors (Stage 5.1): "No project" / "No contract" map to the service's
        // existing remove_project / remove_contract; a missing selector keeps the value.
        $input = $request->all();
        foreach (['project_id' => 'remove_project', 'contract_id' => 'remove_contract'] as $field => $remove) {
            if (array_key_exists($field, $input) && trim((string) $input[$field]) === '') {
                $input[$remove] = '1';
            }
        }
        try {
            $this->expenses->update(CurrentUser::user(), $id, $input);
        } catch (ValidationException $e) {
            return $this->form($this->expenses->find(CurrentUser::user(), $id), $e->errors, $request->all());
        }
        Session::setFlash('success', t('finance.expense.flash.updated'));

        return $this->redirect('/finance/expenses/' . $id);
    }

    public function void(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->back($id, fn () => $this->expenses->void(CurrentUser::user(), $id, (string) $request->input('reason', '')), 'finance.expense.flash.voided');
    }

    public function correct(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->back($id, fn () => $this->expenses->createCorrection(CurrentUser::user(), $id, $request->all()), 'finance.expense.flash.corrected');
    }

    public function uploadReceipt(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->back($id, fn () => $this->expenses->uploadReceipt(CurrentUser::user(), $id, $request->file('file')), 'finance.expense.flash.receipt_added');
    }

    public function deleteReceipt(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        return $this->back($id, fn () => $this->expenses->deleteReceipt(CurrentUser::user(), $id, (int) $request->attribute('attachmentId')), 'finance.expense.flash.receipt_removed');
    }

    public function downloadReceipt(Request $request): Response
    {
        [$attachment, $path] = $this->expenses->receipt(CurrentUser::user(), (int) $request->attribute('id'), (int) $request->attribute('attachmentId'));
        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', (string) $attachment['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace(['"', "\r", "\n"], '', (string) $attachment['filename']) . '"')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** Runs a POST action; a validation error becomes a flash on the detail page. */
    private function back(int $id, callable $action, string $successKey): Response
    {
        try {
            $action();
            Session::setFlash('success', t($successKey));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.expense.flash.failed'));
        }

        return $this->redirect('/finance/expenses/' . $id);
    }

    private function form(?array $expense, array $errors, array $old): Response
    {
        $user = CurrentUser::user();
        $projectId = $this->selected($old, $expense, 'project_id');
        $contractId = $this->selected($old, $expense, 'contract_id');
        $profile = $this->financialProfiles->find((int) $user['organization_id']);
        // "New expense" from a project (D-P1) keeps the project fixed — no selector.
        $projectFixed = $expense === null && !empty($old['project_fixed']) && $projectId > 0;

        return $this->viewWithLayout('finance/expenses/form', 'layout', [
            'expense' => $expense,
            'errors' => $errors,
            'old' => $old,
            'project' => $projectId > 0 ? $this->expenses->projectView($user, $projectId) : null,
            'projectFixed' => $projectFixed,
            // Stage 5.1 selectors: only choices the server would accept; null = module not available.
            'projectOptions' => $projectFixed || !$this->expenses->projectsAvailable($user) ? null : $this->expenses->projectOptions($user),
            'contract' => $contractId > 0 ? $this->expenses->contractView($user, $contractId) : null,
            'contractOptions' => $this->expenses->contractsAvailable($user) ? $this->expenses->contractOptions($user) : null,
            'contacts' => $this->contacts->search([]),
            'companies' => $this->companies->search([]),
            'currencies' => $this->currencies->all(),
            'defaultCurrencyId' => $profile['base_currency_id'] ?? null,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Currency codes for the given rows (an expense keeps its code even if
     * that currency was deactivated later).
     *
     * @return array<int, string> currency id => code
     */
    private function codes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $id = (int) ($row['currency_id'] ?? 0);
            if ($id > 0 && !isset($codes[$id])) {
                $codes[$id] = (string) ($this->currencies->findById($id)['code'] ?? '');
            }
        }

        return $codes;
    }
}
