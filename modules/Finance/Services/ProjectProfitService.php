<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Finance\ExpenseRepository;
use Modules\Finance\ProjectRevenueRepository;

/**
 * Project profit and margin (docs/project-finance-design.md, Stage 5).
 * Read only; per currency, no FX, never a cross-currency total.
 *
 *   Net revenue (per issued invoice attributed to the project)
 *       = (grand_total − tax_total) − credited × (grand_total − tax_total) / grand_total
 *       = 0 when grand_total = 0
 *     Drafts, cancelled invoices (and their credit notes), proformas, payments,
 *     installments, contract value and unattributed invoices are never revenue.
 *   Actual cost = Σ signed amount of the project's recorded expense rows
 *     (original +, increase +, decrease −, void excluded) — Stage 4's
 *     ExpenseRepository::recordedForProject + ExpenseService::signed(), read only.
 *   Profit   = Net revenue − Actual cost (may be negative)
 *   Margin % = Profit / Net revenue × 100, only when Net revenue > 0, else null ("—")
 *
 * Revenue rows: ProjectRevenueRepository::invoicesOfProject (Stage 3-B), read only.
 *
 * Visibility (security decision 9): ALL of finance.budgets.view (record scope on
 * the project), invoices.view at organization / all scope, credit_notes.view and
 * finance.expenses.view at organization / all scope — otherwise nothing at all is
 * computed or shown (no partial profit: Expense = Revenue − Profit would reveal
 * costs the user may not see). Late expenses or credit notes change the figures;
 * that is intended.
 */
final class ProjectProfitService
{
    private const FULL_SCOPES = [Scope::ORGANIZATION, Scope::ALL];

    public function __construct(
        private readonly ProjectRevenueRepository $revenue,
        private readonly ExpenseRepository $expenseRows,
        private readonly ExpenseService $expenses,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /** Decision 9: every one of the four conditions, else the profit section does not exist for this user. */
    public function visibleTo(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, BudgetService::VIEW, $project)
            && in_array($this->authorization->scopeFor($actingUser, Permission::INVOICES_VIEW), self::FULL_SCOPES, true)
            && $this->authorization->can($actingUser, Permission::CREDIT_NOTES_VIEW)
            && in_array($this->authorization->scopeFor($actingUser, ExpenseService::VIEW), self::FULL_SCOPES, true);
    }

    /**
     * @return array<int, array{revenue: string, cost: string, profit: string, margin: ?string}>|null
     *   currency id => figures (sorted by currency id); null when not visible to this user
     */
    public function profitFor(array $actingUser, array $project): ?array
    {
        if (!$this->visibleTo($actingUser, $project)) {
            return null;
        }
        $projectId = (int) $project['id'];

        $revenue = [];
        foreach ($this->revenue->invoicesOfProject($projectId) as $invoice) {
            if ($invoice['status'] !== 'issued' || !$this->authorization->can($actingUser, Permission::INVOICES_VIEW, $invoice)) {
                continue;
            }
            $cur = (int) $invoice['currency_id'];
            $revenue[$cur] = bcadd($revenue[$cur] ?? '0', $this->netRevenue($invoice), 18);
        }

        $cost = [];
        foreach ($this->expenseRows->recordedForProject($projectId) as $row) {
            if (!$this->authorization->can($actingUser, ExpenseService::VIEW, $row)) {
                continue;
            }
            $cur = (int) $row['currency_id'];
            $cost[$cur] = bcadd($cost[$cur] ?? '0', $this->expenses->signed($row), 18);
        }

        $out = [];
        foreach (array_unique([...array_keys($revenue), ...array_keys($cost)]) as $cur) {
            $r = $revenue[$cur] ?? '0';
            $c = $cost[$cur] ?? '0';
            $profit = bcsub($r, $c, 18);
            $out[$cur] = [
                'revenue' => $r,
                'cost' => $c,
                'profit' => $profit,
                'margin' => $this->margin($profit, $r),
            ];
        }
        ksort($out);

        return $out;
    }

    /** Revenue excluding tax, less this invoice's credit notes in its own net / gross proportion. */
    public function netRevenue(array $invoice): string
    {
        $grand = (string) $invoice['grand_total'];
        if (bccomp($grand, '0', 18) === 0) {
            return '0';
        }
        $net = bcsub($grand, (string) $invoice['tax_total'], 18);
        $creditedNet = bcdiv(bcmul((string) ($invoice['credited'] ?? '0'), $net, 18), $grand, 18);

        return bcsub($net, $creditedNet, 18);
    }

    /** Profit / Revenue × 100, only when Revenue > 0. */
    public function margin(string $profit, string $revenue): ?string
    {
        return bccomp($revenue, '0', 18) > 0 ? bcdiv(bcmul($profit, '100', 18), $revenue, 18) : null;
    }
}
