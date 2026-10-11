<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\BudgetService;
use Modules\Finance\Services\ExpenseService;
use Modules\Finance\Services\ProjectProfitService;
use Modules\Finance\Services\ProjectInvoiceService;
use Modules\Finance\Services\ProjectRevenueService;

/**
 * "Billing" box on the contract page (docs/contract-finance-design.md),
 * contributed through RelatedPanelRegistry — Contracts doesn't know this
 * module exists. RelatedPanelRegistry shows it only while Finance is
 * available to the user; inside, each section needs its own view
 * permission (quotes.view / invoices.view) and each row Finance's record
 * scope. Nothing is rendered when the user holds neither view permission.
 */
final class FinancePanels
{
    public function __construct(
        private readonly ContractBillingService $billing,
        private readonly AuthorizationEngine $authorization,
        private readonly TemplateEngine $templates,
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
        private readonly ?ProjectRevenueService $projectRevenue = null,
        private readonly ?ProjectInvoiceService $projectInvoices = null,
        private readonly ?ExpenseService $expenses = null,
        private readonly ?BudgetService $budgets = null,
        private readonly ?ProjectProfitService $profit = null,
    ) {
    }

    public function registerAll(RelatedPanelRegistry $panels): void
    {
        $panels->register(new RelatedPanel(
            subjectType: ContractBillingService::LINKED_TYPE,
            id: 'finance.contract',
            module: 'finance',
            titleKey: 'finance.billing',
            render: fn (array $subject, array $user): string => $this->render($subject, $user),
            order: 45,
            permission: null, // per section below
            icon: 'receipt',
        ));

        // "Project revenue" on a project page (Stage 3-B): invoices.view, the project
        // viewable through the registry; only invoices the user may view count.
        if ($this->projectRevenue !== null) {
            $panels->register(new RelatedPanel(
                subjectType: ProjectRevenueService::LINKED_TYPE,
                id: 'finance.project',
                module: 'finance',
                titleKey: 'finance.project.title',
                render: fn (array $subject, array $user): string => $this->renderProject($subject, $user),
                order: 45,
                permission: Permission::INVOICES_VIEW,
                icon: 'receipt',
            ));
        }

        // "Project costs" (Phase 4, E10): a SEPARATE panel — finance.expenses.view and the
        // project viewable through the registry; only expenses the user may view count.
        if ($this->expenses !== null) {
            $panels->register(new RelatedPanel(
                subjectType: 'project',
                id: 'finance.project_costs',
                module: 'finance',
                titleKey: 'finance.expense.project_title',
                render: fn (array $subject, array $user): string => $this->renderProjectCosts($subject, $user),
                order: 46,
                permission: ExpenseService::VIEW,
                icon: 'coins',
            ));
        }

        // "Budget" (Stage 5): finance.budgets.view, re-checked in the renderer with record
        // scope on the project, which must be viewable through the registry.
        if ($this->budgets !== null) {
            $panels->register(new RelatedPanel(
                subjectType: 'project',
                id: 'finance.project_budget',
                module: 'finance',
                titleKey: 'finance.budget.title',
                render: fn (array $subject, array $user): string => $this->renderProjectBudget($subject, $user),
                order: 47,
                permission: BudgetService::VIEW,
                icon: 'wallet',
            ));
        }

        // "Profitability" (Stage 5): only for users who pass all four visibility
        // conditions (ProjectProfitService::visibleTo) — otherwise nothing at all.
        if ($this->profit !== null && $this->budgets !== null) {
            $panels->register(new RelatedPanel(
                subjectType: 'project',
                id: 'finance.project_profit',
                module: 'finance',
                titleKey: 'finance.profit.title',
                render: fn (array $subject, array $user): string => $this->renderProjectProfit($subject, $user),
                order: 48,
                permission: BudgetService::VIEW,
                icon: 'bar-chart',
            ));
        }
    }

    public function renderProjectBudget(array $subject, array $user): string
    {
        $projectId = (int) ($subject['id'] ?? 0);
        $project = $this->budgets?->project($user, $projectId);
        if ($project === null || !$this->budgets->canView($user, $project)) {
            return '';
        }

        $data = $this->budgets->panel($user, $project);
        $codes = [];
        foreach (array_keys($data['currencies']) as $id) {
            $codes[$id] ??= $this->currencies->findById((int) $id)['code'] ?? '';
        }
        $translator = Locale::get();

        return $this->templates->render('finance/_project_budget', [
            'currencies' => $data['currencies'],
            'hasBudget' => $data['has_budget'],
            'showActual' => $data['show_actual'],
            'canEdit' => $data['can_edit'],
            'readOnly' => $data['read_only'],
            'projectId' => $projectId,
            'codes' => $codes,
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
        ]);
    }

    public function renderProjectProfit(array $subject, array $user): string
    {
        $projectId = (int) ($subject['id'] ?? 0);
        $project = $this->budgets?->project($user, $projectId);
        $profit = $project !== null ? $this->profit?->profitFor($user, $project) : null;
        if ($profit === null) {
            return '';
        }

        $codes = [];
        foreach (array_keys($profit) as $id) {
            $codes[$id] ??= $this->currencies->findById((int) $id)['code'] ?? '';
        }
        $translator = Locale::get();

        return $this->templates->render('finance/_project_profit', [
            'profit' => $profit,
            'codes' => $codes,
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
        ]);
    }

    public function renderProjectCosts(array $subject, array $user): string
    {
        $projectId = (int) ($subject['id'] ?? 0);
        $can = fn (string $key): bool => $this->authorization->can($user, $key);
        $project = $this->expenses?->viewableProject($user, $projectId);
        if ($project === null || !$can(ExpenseService::VIEW)) {
            return '';
        }

        $data = $this->expenses->projectPanel($user, $project, $can);
        $codes = [];
        foreach ([...array_map(fn ($id) => ['currency_id' => $id], array_keys($data['totals'])), ...$data['recent']] as $row) {
            $id = (int) $row['currency_id'];
            $codes[$id] ??= $this->currencies->findById($id)['code'] ?? '';
        }

        return $this->templates->render('finance/_project_costs', [
            'totals' => $data['totals'],
            'recent' => $data['recent'],
            'canCreate' => $data['can_create'],
            'projectId' => $projectId,
            'codes' => $codes,
        ]);
    }

    public function renderProject(array $subject, array $user): string
    {
        $projectId = (int) ($subject['id'] ?? 0);
        $can = fn (string $key): bool => $this->authorization->can($user, $key);
        $project = $this->projectRevenue?->project($user, $projectId);
        if ($project === null || !$can(Permission::INVOICES_VIEW)) {
            return '';
        }

        $data = $this->projectRevenue->panelFor($user, $project, $can);
        $codes = [];
        foreach ([...$data['summary'], ...$data['documents'], ...$data['candidates']] as $row) {
            $id = (int) $row['currency_id'];
            $codes[$id] ??= $this->currencies->findById($id)['code'] ?? '';
        }
        $translator = Locale::get();

        return $this->templates->render('finance/_project_panel', [
            'summary' => $data['summary'],
            'documents' => $data['documents'],
            'showCredits' => $data['show_credits'],
            'canAttach' => $data['can_attach'],
            'candidates' => $data['candidates'],
            'detachable' => $data['detachable'],
            // Stage 3-C: "New invoice" only when the server would accept it.
            'canCreateInvoice' => $can(Permission::INVOICES_CREATE) && ($this->projectInvoices?->canCreateFrom($user, $project) ?? false),
            'projectId' => $projectId,
            'codes' => $codes,
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
        ]);
    }

    public function render(array $subject, array $user): string
    {
        $contractId = (int) ($subject['id'] ?? 0);
        $canView = fn (string $key): bool => $this->authorization->can($user, $key);

        if ($contractId <= 0 || (!$canView(Permission::QUOTES_VIEW) && !$canView(Permission::INVOICES_VIEW))) {
            return '';
        }

        $data = $this->billing->panelFor($user, $contractId, $canView);
        // A button only when the server would accept it: create permission AND
        // the contract viewable by this user (RecordTypeRegistry, record scope).
        $contractOk = $this->billing->contract($user, $contractId) !== null;
        $translator = Locale::get();

        // Payment schedule (Stage 2): invoices.view + the contract viewable through the registry.
        $schedule = null;
        $installments = $this->billing->installments();
        if ($installments !== null && $canView(Permission::INVOICES_VIEW)) {
            $contract = $installments->contract($user, $contractId);
            $schedule = $contract !== null ? $installments->scheduleFor($user, $contract, $canView) : null;
        }

        $codes = [];
        $scheduleRows = [];
        foreach ($schedule['rows'] ?? [] as $row) {
            $scheduleRows[] = $row;
            if ($row['document'] !== null) {
                $scheduleRows[] = $row['document'];
            }
        }
        if (($schedule['currency_id'] ?? null) !== null) {
            $scheduleRows[] = ['currency_id' => $schedule['currency_id']];
        }
        foreach ([...($data['proformas'] ?? []), ...($data['invoices'] ?? []), ...$data['summary'], ...$scheduleRows] as $row) {
            $id = (int) $row['currency_id'];
            $codes[$id] ??= $this->currencies->findById($id)['code'] ?? '';
        }
        if (!empty($subject['currency_id'])) {
            $codes[(int) $subject['currency_id']] ??= $this->currencies->findById((int) $subject['currency_id'])['code'] ?? '';
        }

        return $this->templates->render('finance/_contract_panel', [
            'proformas' => $data['proformas'],
            'invoices' => $data['invoices'],
            'summary' => $data['summary'],
            'contractValue' => $subject['value'] ?? null,
            'contractCurrencyId' => !empty($subject['currency_id']) ? (int) $subject['currency_id'] : null,
            'codes' => $codes,
            'canCreateProforma' => $contractOk && $this->authorization->can($user, Permission::QUOTES_CREATE),
            'canCreateInvoice' => $contractOk && $this->authorization->can($user, Permission::INVOICES_CREATE),
            'contractId' => $contractId,
            'schedule' => $schedule,
            'fmt' => new LocaleFormatter($translator !== null ? $translator->locale() : 'en'),
        ]);
    }
}
