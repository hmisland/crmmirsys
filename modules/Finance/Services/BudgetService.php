<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CurrencyRepository;
use Modules\Finance\BudgetRepository;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\Money;

use function App\Infrastructure\Theme\t;

/**
 * Project expense budget (docs/project-finance-design.md, Stage 5).
 *
 * A budget is a set of lines, one per expense category × currency, amount
 * ≥ 0. Figures are per currency only — never added across currencies, no FX.
 * Budget vs Actual per currency × category:
 *   Remaining  = Budget − Actual
 *   Variance   = Actual − Budget        (positive = over budget)
 *   Variance % = Variance / Budget × 100, only when Budget > 0
 * and "—" for every budget figure where no line exists. Actual is the
 * Stage 4 net actual cost of the expenses this user may view
 * (ExpenseRepository::recordedForProject + ExpenseService::signed(), read
 * only), shown only with finance.expenses.view.
 *
 * Access: finance.budgets.view with record scope on the PROJECT row (and the
 * project viewable through the registry). Editing also needs
 * finance.budgets.manage and projects.edit (record scope on the project), and
 * the project in draft / planned / active / on_hold; completed and cancelled
 * budgets are read-only. Saving replaces the whole budget in one transaction
 * with the project's lines locked FOR UPDATE; the project timeline gets a
 * generic "Budget updated" entry without any amount.
 */
final class BudgetService
{
    public const VIEW = 'finance.budgets.view';
    public const MANAGE = 'finance.budgets.manage';
    public const EDITABLE_STATUSES = ['draft', 'planned', 'active', 'on_hold'];
    public const NOTES_MAX = 255;
    public const LINES_MAX = 200;
    private const PROJECT_EDIT = 'projects.edit';
    private const PROJECT_TYPE = 'project';
    private const EXPENSES_VIEW = 'finance.expenses.view';

    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly ExpenseRepository $expenseRows,
        private readonly ExpenseService $expenses,
        private readonly ?RecordTypeRegistry $recordTypes = null,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
    ) {
    }

    /** The project row if this user may view it (Projects available, projects.view, organization, record scope), else null. */
    public function project(array $actingUser, int $projectId): ?array
    {
        return $projectId > 0 ? $this->recordTypes?->find(self::PROJECT_TYPE, $projectId, $actingUser) : null;
    }

    public function projectUrl(int $projectId): ?string
    {
        return $this->recordTypes?->get(self::PROJECT_TYPE)?->showUrl($projectId);
    }

    public function canView(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, self::VIEW, $project);
    }

    public function isEditableStatus(array $project): bool
    {
        return in_array((string) ($project['status'] ?? ''), self::EDITABLE_STATUSES, true);
    }

    /** Whether the server would accept a budget save: both permissions on the project and an editable status. */
    public function canEdit(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, self::MANAGE, $project)
            && $this->authorization->can($actingUser, self::PROJECT_EDIT, $project)
            && $this->isEditableStatus($project);
    }

    /**
     * Budget vs Actual for the project panel.
     *
     * @return array{currencies: array<int, array{rows: array, total: array}>, has_budget: bool, show_actual: bool, can_edit: bool, read_only: bool}
     */
    public function panel(array $actingUser, array $project): array
    {
        $budget = [];
        foreach ($this->budgets->linesForProject((int) $project['id']) as $line) {
            $budget[(int) $line['currency_id']][(string) $line['category']] = (string) $line['amount'];
        }

        $showActual = $this->authorization->can($actingUser, self::EXPENSES_VIEW);
        $actual = $showActual ? $this->actualByCurrencyAndCategory($actingUser, (int) $project['id']) : [];

        $currencies = [];
        foreach (array_unique([...array_keys($budget), ...array_keys($actual)]) as $currencyId) {
            $rows = [];
            $totalBudget = null;
            $totalActual = '0';
            foreach (ExpenseService::CATEGORIES as $category) {
                $b = $budget[$currencyId][$category] ?? null;
                $a = $actual[$currencyId][$category] ?? null;
                if ($b === null && $a === null) {
                    continue;
                }
                $rows[] = $this->figures($category, $b, $showActual ? ($a ?? '0') : null);
                if ($b !== null) {
                    $totalBudget = bcadd($totalBudget ?? '0', $b, 18);
                }
                $totalActual = bcadd($totalActual, $a ?? '0', 18);
            }
            $currencies[$currencyId] = ['rows' => $rows, 'total' => $this->figures(null, $totalBudget, $showActual ? $totalActual : null)];
        }
        ksort($currencies);

        return [
            'currencies' => $currencies,
            'has_budget' => $budget !== [],
            'show_actual' => $showActual,
            'can_edit' => $this->canEdit($actingUser, $project),
            'read_only' => !$this->isEditableStatus($project),
        ];
    }

    /**
     * One Budget vs Actual row. With no budget line, every budget figure is null ("—").
     *
     * @return array{category: ?string, budget: ?string, actual: ?string, remaining: ?string, variance: ?string, variance_pct: ?string}
     */
    public function figures(?string $category, ?string $budget, ?string $actual): array
    {
        $hasBoth = $budget !== null && $actual !== null;
        $variance = $hasBoth ? bcsub($actual, $budget, 18) : null;

        return [
            'category' => $category,
            'budget' => $budget,
            'actual' => $actual,
            'remaining' => $hasBoth ? bcsub($budget, $actual, 18) : null,
            'variance' => $variance,
            'variance_pct' => $variance !== null && bccomp($budget, '0', 18) > 0 ? bcdiv(bcmul($variance, '100', 18), $budget, 18) : null,
        ];
    }

    /**
     * The edit form's data. 404 when the project is not viewable, 403 without
     * both permissions; a read-only (completed / cancelled) budget → 422.
     *
     * @return array{project: array, lines: array}
     */
    public function formFor(array $actingUser, int $projectId): array
    {
        $project = $this->editableProject($actingUser, $projectId);

        return ['project' => $project, 'lines' => $this->budgets->linesForProject($projectId)];
    }

    /**
     * Replaces the project's whole budget with $input['lines'] (blank rows
     * ignored; no lines = no budget). Validation first, then one transaction:
     * lock the project's lines FOR UPDATE, delete, insert, timeline.
     */
    public function replace(array $actingUser, int $projectId, array $input): void
    {
        $this->editableProject($actingUser, $projectId);
        $current = $this->budgets->linesForProject($projectId);
        $lines = $this->validated($input['lines'] ?? [], array_map(fn (array $l) => (int) $l['currency_id'], $current));

        try {
            $this->budgets->atomically(function () use ($actingUser, $projectId, $lines): void {
                $before = $this->budgets->lockLinesForProject($projectId);
                $this->budgets->deleteForProject($projectId);
                foreach ($lines as $line) {
                    $this->budgets->insertLine($line + [
                        'project_id' => $projectId,
                        'created_by_user_id' => (int) $actingUser['id'],
                        'updated_by_user_id' => (int) $actingUser['id'],
                    ]);
                }
                if ($this->signature($before) !== $this->signature($lines)) {
                    $this->timeline->record(self::PROJECT_TYPE, $projectId, 'budget_updated', 'Budget updated', (int) $actingUser['id']);
                }
            });
        } catch (\PDOException $e) {
            // A concurrent save of a project that had no lines yet: nothing to row-lock, so
            // InnoDB resolves the clash with a deadlock / duplicate-key error. The other save
            // committed whole; this one is rolled back whole and the user is asked to retry.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205, 1062], true)) {
                throw new ValidationException(['budget' => [t('finance.budget.error.concurrent')]]);
            }
            throw $e;
        }
    }

    // ---- Internals -------------------------------------------------------------

    /** Viewable project (404), both permissions with project scope (403), editable status (422). */
    private function editableProject(array $actingUser, int $projectId): array
    {
        $project = $this->project($actingUser, $projectId);
        if ($project === null) {
            throw new NotFoundException('Project not found.');
        }
        if (!$this->authorization->can($actingUser, self::MANAGE, $project)
            || !$this->authorization->can($actingUser, self::PROJECT_EDIT, $project)) {
            throw new AuthorizationException();
        }
        if (!$this->isEditableStatus($project)) {
            throw new ValidationException(['budget' => [t('finance.budget.error.read_only')]]);
        }

        return $project;
    }

    /** @return array<int, array<string, string>> currency id => category => signed net of the visible recorded rows */
    private function actualByCurrencyAndCategory(array $actingUser, int $projectId): array
    {
        $actual = [];
        foreach ($this->expenseRows->recordedForProject($projectId) as $row) {
            if (!$this->authorization->can($actingUser, self::EXPENSES_VIEW, $row)) {
                continue;
            }
            $cur = (int) $row['currency_id'];
            $cat = (string) $row['category'];
            $actual[$cur][$cat] = bcadd($actual[$cur][$cat] ?? '0', $this->expenses->signed($row), 18);
        }

        return $actual;
    }

    /**
     * @param mixed $rows the submitted lines
     * @param int[] $currentCurrencies currencies already on this budget (kept valid even if since deactivated)
     * @return array<int, array{category: string, currency_id: int, amount: string, notes: ?string}>
     */
    private function validated(mixed $rows, array $currentCurrencies): array
    {
        if (!is_array($rows)) {
            $rows = [];
        }
        if (count($rows) > self::LINES_MAX) {
            throw new ValidationException(['budget' => [t('finance.budget.error.too_many_lines')]]);
        }
        $active = [];
        foreach ($this->currencies->all() as $c) { // active currencies only
            $active[(int) $c['id']] = true;
        }

        $errors = [];
        $lines = [];
        $seen = [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $category = trim((string) ($row['category'] ?? ''));
            $currencyRaw = trim((string) ($row['currency_id'] ?? ''));
            $amount = str_replace([',', ' '], '', trim((string) ($row['amount'] ?? '')));
            $notes = trim((string) ($row['notes'] ?? ''));
            if ($amount === '' && $notes === '' && ($category === '' || $currencyRaw === '')) {
                continue; // a blank row of the form
            }

            if (!in_array($category, ExpenseService::CATEGORIES, true)) {
                $errors["lines.{$i}.category"][] = t('finance.budget.error.invalid_category');
            }
            $currencyId = ctype_digit($currencyRaw) ? (int) $currencyRaw : 0;
            if ($currencyId <= 0 || (!isset($active[$currencyId]) && !in_array($currencyId, $currentCurrencies, true))) {
                $errors["lines.{$i}.currency_id"][] = t('finance.error.invalid_currency');
            }
            if (preg_match('/^\d{1,20}(\.\d{1,18})?$/', $amount) !== 1) {
                $errors["lines.{$i}.amount"][] = t('finance.budget.error.invalid_amount');
            } elseif ($currencyId > 0 && Money::fractionDigits($amount) > Money::decimalsFor($currencyId)) {
                // Stage 5.2 (L2): at most the currency's money precision (IRR 3) — never truncated.
                $errors["lines.{$i}.amount"][] = t('finance.error.amount_precision', ['max' => Money::decimalsFor($currencyId)]);
            }
            if (mb_strlen($notes) > self::NOTES_MAX) {
                $errors["lines.{$i}.notes"][] = t('finance.budget.error.notes_too_long');
            }
            $key = $category . '|' . $currencyId;
            if (isset($seen[$key])) {
                $errors["lines.{$i}.category"][] = t('finance.budget.error.duplicate_line');
            }
            $seen[$key] = true;

            $lines[] = ['category' => $category, 'currency_id' => $currencyId, 'amount' => $amount, 'notes' => $notes !== '' ? $notes : null];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $lines;
    }

    /** Order-independent fingerprint of a budget (category, currency, amount, notes) to skip no-op timeline entries. */
    private function signature(array $lines): string
    {
        $parts = array_map(fn (array $l): string => $l['category'] . '|' . (int) $l['currency_id'] . '|'
            . bcadd((string) $l['amount'], '0', 18) . '|' . ($l['notes'] ?? ''), $lines);
        sort($parts);

        return implode("\n", $parts);
    }
}
