<?php

declare(strict_types=1);

namespace Modules\Finance\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\BudgetService;

use function App\Infrastructure\Theme\t;

/**
 * Project expense budget form (Stage 5): GET / POST /finance/projects/{id}/budget.
 * Thin: every rule — project scope, both permissions, status, validation, the
 * locked replace — lives in BudgetService. Routes add Auth + Finance module +
 * RbacMiddleware(finance.budgets.manage) + CSRF on the POST.
 */
final class BudgetController extends BaseController
{
    /** Empty rows offered for new lines (blank rows are ignored on save). */
    private const BLANK_ROWS = 6;

    public function __construct(
        TemplateEngine $templates,
        private readonly BudgetService $budgets,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
    ) {
        parent::__construct($templates);
    }

    public function show(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');
        try {
            $data = $this->budgets->formFor(CurrentUser::user(), $projectId);
        } catch (ValidationException $e) {
            return $this->toProject($projectId, $e);
        }

        return $this->form($data['project'], $data['lines'], []);
    }

    public function save(Request $request): Response
    {
        $projectId = (int) $request->attribute('id');
        $input = $request->all();
        try {
            $this->budgets->replace(CurrentUser::user(), $projectId, $input);
        } catch (ValidationException $e) {
            if (isset($e->errors['budget'])) {
                return $this->toProject($projectId, $e); // read-only status / concurrent save / too many lines
            }
            $data = $this->budgets->formFor(CurrentUser::user(), $projectId);

            return $this->form($data['project'], is_array($input['lines'] ?? null) ? array_values($input['lines']) : [], $e->errors);
        }
        Session::setFlash('success', t('finance.budget.flash.saved'));

        return $this->redirect($this->budgets->projectUrl($projectId) ?? '/finance/projects/' . $projectId . '/budget');
    }

    private function toProject(int $projectId, ValidationException $e): Response
    {
        Session::setFlash('error', array_values($e->errors)[0][0] ?? t('finance.budget.flash.failed'));

        return $this->redirect($this->budgets->projectUrl($projectId) ?? '/finance');
    }

    /** @param array $lines saved lines, or the submitted rows when re-shown with errors */
    private function form(array $project, array $lines, array $errors): Response
    {
        $currencies = [];
        foreach ($this->currencies->all() as $c) {
            $currencies[(int) $c['id']] = (string) $c['code'];
        }
        // A currency already on this budget stays selectable even if deactivated since.
        foreach ($lines as $line) {
            $id = (int) ($line['currency_id'] ?? 0);
            if ($id > 0 && !isset($currencies[$id])) {
                $currencies[$id] = (string) ($this->currencies->findById($id)['code'] ?? '');
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            $amount = (string) ($line['amount'] ?? '');
            $rows[] = [
                'category' => (string) ($line['category'] ?? ''),
                'currency_id' => (string) ($line['currency_id'] ?? ''),
                'amount' => $errors === [] && str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount,
                'notes' => (string) ($line['notes'] ?? ''),
            ];
        }
        for ($i = 0; $i < self::BLANK_ROWS; $i++) {
            $rows[] = ['category' => '', 'currency_id' => '', 'amount' => '', 'notes' => ''];
        }

        return $this->viewWithLayout('finance/budgets/form', 'layout', [
            'project' => $project,
            'projectUrl' => $this->budgets->projectUrl((int) $project['id']),
            'rows' => $rows,
            'errors' => $errors,
            'currencies' => $currencies,
            'categories' => \Modules\Finance\Services\ExpenseService::CATEGORIES,
        ], $errors === [] ? 200 : 422);
    }
}
