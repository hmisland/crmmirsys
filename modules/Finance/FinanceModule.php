<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Application\Services\OrganizationService;
use App\Application\Services\AttachmentService;
use App\Application\Services\TimelineService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Pdf\PdfRenderer;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CountryRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\TaxJurisdictionRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Controllers\BudgetController;
use Modules\Finance\Controllers\CreditNoteController;
use Modules\Finance\Controllers\DocumentPrintController;
use Modules\Finance\Controllers\FinancialPeriodController;
use Modules\Finance\Controllers\ExpenseController;
use Modules\Finance\Controllers\InstallmentController;
use Modules\Finance\Controllers\ProjectRevenueController;
use Modules\Finance\Controllers\FinanceSettingsController;
use Modules\Finance\Controllers\InvoiceController;
use Modules\Finance\Controllers\PaymentController;
use Modules\Finance\Controllers\ProductController;
use Modules\Finance\Controllers\ProformaController;
use Modules\Finance\Controllers\ReportController;
use Modules\Finance\Services\BudgetService;
use Modules\Finance\Services\ContractBillingService;
use Modules\Finance\Services\CreditNoteService;
use Modules\Finance\Services\DocumentPrintService;
use Modules\Finance\Services\FinancialPeriodService;
use Modules\Finance\Services\FinancialProfileService;
use Modules\Finance\Services\ExpenseService;
use Modules\Finance\Services\InstallmentService;
use Modules\Finance\Services\ProjectInvoiceService;
use Modules\Finance\Services\ProjectProfitService;
use Modules\Finance\Services\ProjectRevenueService;
use Modules\Finance\Services\InvoiceService;
use Modules\Finance\Services\PaymentService;
use Modules\Finance\Services\ProductService;
use Modules\Finance\Services\ProformaService;
use Modules\Finance\Services\ReportService;
use Modules\Finance\Services\TaxCalculationService;

/**
 * Covers sub-phases 12.1 (Foundation) through 12.8 (Financial
 * periods + basic reports) so far. Wallet integration, the REST
 * API, and automated tests are added compatible with this same
 * module in their own later sub-phases, extending this module
 * rather than creating new ones, per the agreed phase breakdown.
 */
final class FinanceModule implements ModuleInterface
{
    private ?FinanceSettingsController $settingsController = null;
    private ?ProductController $productController = null;
    private ?ProformaController $proformaController = null;
    private ?InvoiceController $invoiceController = null;
    private ?PaymentController $paymentController = null;
    private ?CreditNoteController $creditNoteController = null;
    private ?FinancialPeriodController $periodController = null;
    private ?ReportController $reportController = null;
    private ?DocumentPrintController $printController = null;
    private ?InstallmentController $installmentController = null;
    private ?ProjectRevenueController $projectRevenueController = null;
    private ?ExpenseController $expenseController = null;
    private ?BudgetController $budgetController = null;
    private ?ProductService $productServiceInstance = null;
    private ?ProformaService $proformaServiceInstance = null;
    private ?InvoiceService $invoiceServiceInstance = null;
    private ?PaymentService $paymentServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read counts without hard-depending on this module — same lazy pattern as Notification/Wallet/Sales/Mail/Support. */
    public function productService(): ?ProductService
    {
        return $this->productServiceInstance;
    }

    public function proformaService(): ?ProformaService
    {
        return $this->proformaServiceInstance;
    }

    public function invoiceService(): ?InvoiceService
    {
        return $this->invoiceServiceInstance;
    }

    /** Read-only consumer: Client Records shows a client's payments through this (payments.view still applies). */
    public function paymentService(): ?PaymentService
    {
        return $this->paymentServiceInstance;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);

        // Expenses (Phase 4, E12): module-registered keys — Permission.php is not changed.
        Permission::registerModuleKeys('finance', [ExpenseService::VIEW, ExpenseService::MANAGE]);
        // Budgets (Stage 5, decision 10): module-registered keys — Permission.php is not changed.
        Permission::registerModuleKeys('finance', [BudgetService::VIEW, BudgetService::MANAGE]);
        $currencies = new CurrencyRepository();
        $taxProfiles = new TaxProfileRepository();
        $financialProfiles = new FinancialProfileRepository();
        $contacts = new ContactRepository();
        $templates = $container->make(TemplateEngine::class);
        $taxCalculation = new TaxCalculationService(new TaxRuleRepository());
        $financialPeriods = new FinancialPeriodService(new FinancialPeriodRepository(), $authorization, $container->make(TimelineService::class));

        $profileService = new FinancialProfileService($financialProfiles, $authorization);

        $this->settingsController = new FinanceSettingsController(
            $templates,
            $profileService,
            new CountryRepository(),
            new TaxJurisdictionRepository(),
            $currencies,
            $taxProfiles
        );

        $productService = new ProductService(new ProductRepository(), $authorization);
        $this->productServiceInstance = $productService;

        $this->productController = new ProductController(
            $templates,
            $productService,
            $currencies,
            $taxProfiles
        );

        // Contract ↔ Finance links (docs/contract-finance-design.md).
        $documentLinks = new FinanceDocumentLinkRepository();

        $proformaRepo = new ProformaRepository();
        $proformaItemRepo = new ProformaItemRepository();

        $proformaService = new ProformaService(
            $proformaRepo,
            $proformaItemRepo,
            $financialProfiles,
            $taxCalculation,
            $authorization,
            documentLinks: $documentLinks,
        );
        $this->proformaServiceInstance = $proformaService;


        $invoiceRepo = new InvoiceRepository();
        $creditNoteRepo = new CreditNoteRepository();

        $invoiceService = new InvoiceService(
            $invoiceRepo,
            new InvoiceItemRepository(),
            new InvoiceNumberSeriesRepository(),
            $proformaRepo,
            $proformaItemRepo,
            $financialProfiles,
            $taxCalculation,
            $authorization,
            $financialPeriods,
            documentLinks: $documentLinks,
            timeline: $container->make(TimelineService::class),
        );
        $this->invoiceServiceInstance = $invoiceService;

        // Contract installment schedule (Stage 2): Finance-owned, contracts read
        // through RecordTypeRegistry only.
        $installmentService = new InstallmentService(
            new InstallmentRepository(),
            $documentLinks,
            $proformaRepo,
            $invoiceRepo,
            $creditNoteRepo,
            $proformaService,
            $invoiceService,
            $authorization,
            $container->make(TimelineService::class),
            $container->make(RecordTypeRegistry::class),
        );
        $this->installmentController = new InstallmentController($templates, $installmentService);

        // Reads contracts ONLY through RecordTypeRegistry; Contracts is never imported.
        $contractBilling = new ContractBillingService(
            $proformaService,
            $invoiceService,
            $documentLinks,
            $container->make(TimelineService::class),
            $container->make(RecordTypeRegistry::class),
            installments: $installmentService,
        );

        $paymentService = new PaymentService(new PaymentRepository(), $invoiceRepo, $creditNoteRepo, $authorization, $financialPeriods, timeline: $container->make(TimelineService::class));
        $this->paymentServiceInstance = $paymentService;

        $creditNoteService = new CreditNoteService(
            $creditNoteRepo,
            new CreditNoteNumberSeriesRepository(),
            $invoiceRepo,
            $authorization,
            $financialPeriods,
            timeline: $container->make(TimelineService::class),
        );

        // "Billing" box on the contract page, via the Core panel slot only.
        // Project revenue (Stage 3-B): invoices attributed to a project, read through the registry only.
        $projectRevenue = new ProjectRevenueService(
            new ProjectRevenueRepository(),
            $documentLinks,
            $invoiceRepo,
            $authorization,
            $container->make(TimelineService::class),
            $container->make(RecordTypeRegistry::class),
        );
        $this->projectRevenueController = new ProjectRevenueController($templates, $projectRevenue);

        // "New invoice" from a project (Stage 3-C): a draft + its project link, atomically.
        $projectInvoices = new ProjectInvoiceService(
            $invoiceService,
            $documentLinks,
            $projectRevenue,
            $authorization,
            $container->make(TimelineService::class),
        );

        // Expenses (Phase 4). The `expense` record type is registered WITHOUT Core record
        // support (E6): receipts go only through Finance's own routes, which check scope,
        // the manage permission, status and the financial period.
        $expenseRepo = new ExpenseRepository();
        $container->make(RecordTypeRegistry::class)->register(new RecordType(
            key: ExpenseService::SUBJECT,
            module: 'finance',
            viewPermission: ExpenseService::VIEW,
            labelKey: 'finance.expense.record',
            showPath: '/finance/expenses/{id}',
            icon: 'coins',
            finder: fn (int $id): ?array => $expenseRepo->findById($id),
            recordSupport: false,
        ));
        $expenseService = new ExpenseService(
            $expenseRepo,
            $authorization,
            $container->make(TimelineService::class),
            $container->make(AttachmentService::class),
            $container->make(RecordTypeRegistry::class),
        );
        $this->expenseController = new ExpenseController($templates, $expenseService);

        // Budget, profit and margin (Stage 5). Read only over Stage 3-B revenue rows
        // (ProjectRevenueRepository) and Stage 4 expense rows (ExpenseRepository +
        // ExpenseService::signed); neither is changed. Per currency, no FX.
        $budgetService = new BudgetService(
            new BudgetRepository(),
            $authorization,
            $container->make(TimelineService::class),
            $expenseRepo,
            $expenseService,
            $container->make(RecordTypeRegistry::class),
        );
        $profitService = new ProjectProfitService(new ProjectRevenueRepository(), $expenseRepo, $expenseService, $authorization);
        $this->budgetController = new BudgetController($templates, $budgetService);

        (new FinancePanels($contractBilling, $authorization, $templates, projectRevenue: $projectRevenue, projectInvoices: $projectInvoices, expenses: $expenseService, budgets: $budgetService, profit: $profitService))->registerAll($container->make(RelatedPanelRegistry::class));

        $this->proformaController = new ProformaController(
            $templates,
            $proformaService,
            $contacts,
            $currencies,
            $taxProfiles,
            new ProductRepository(),
            $financialProfiles,
            $contractBilling,
        );
        $this->invoiceController = new InvoiceController(
            $templates,
            $invoiceService,
            $contacts,
            $currencies,
            $taxProfiles,
            new ProductRepository(),
            $authorization,
            $paymentService,
            $financialProfiles,
            $creditNoteService,
            $contractBilling,
            timeline: $container->make(TimelineService::class),
            projectInvoices: $projectInvoices,
        );

        $this->paymentController = new PaymentController($templates, $paymentService);
        $this->creditNoteController = new CreditNoteController($templates, $creditNoteService);
        $this->periodController = new FinancialPeriodController($templates, $financialPeriods);
        $this->reportController = new ReportController($templates, new ReportService($invoiceRepo, $creditNoteRepo, $authorization));

        $this->printController = new DocumentPrintController(
            $templates,
            new DocumentPrintService(
                $invoiceService,
                $proformaService,
                $creditNoteRepo,
                $financialProfiles,
                new OrganizationRepository(),
                $container->make(OrganizationService::class),
                $contacts,
                new CompanyRepository(),
                $currencies,
                $authorization,
            ),
            new PdfRenderer(dirname(__DIR__, 2), $container->make('app.storage_root') . '/cache/mpdf'),
        );
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('finance');
        $settingsController = $this->settingsController;
        $productController = $this->productController;
        $proformaController = $this->proformaController;
        $invoiceController = $this->invoiceController;
        $paymentController = $this->paymentController;
        $creditNoteController = $this->creditNoteController;
        $periodController = $this->periodController;
        $reportController = $this->reportController;
        $printController = $this->printController;
        $installmentController = $this->installmentController;
        $projectRevenueController = $this->projectRevenueController;
        $expenseController = $this->expenseController;
        $budgetController = $this->budgetController;

        $router->group('/finance', [$auth, $module], function (Router $router) use ($settingsController, $productController, $proformaController, $invoiceController, $paymentController, $creditNoteController, $periodController, $reportController, $printController, $installmentController, $projectRevenueController, $expenseController, $budgetController) {
            $router->get('/settings', fn (Request $r) => $settingsController->show($r), [new RbacMiddleware('finance.settings.view')]);
            $router->post('/settings', fn (Request $r) => $settingsController->update($r), [new CsrfMiddleware()]);

            $router->get('/products', fn (Request $r) => $productController->index($r), [new RbacMiddleware('products.view')]);
            $router->get('/products/create', fn (Request $r) => $productController->showCreate($r), [new RbacMiddleware('products.create')]);
            // Product picker for proforma/invoice rows (permission checked in ProductService::lookup).
            $router->get('/products/lookup', fn (Request $r) => $productController->lookup($r));
            $router->post('/products', fn (Request $r) => $productController->create($r), [new RbacMiddleware('products.create'), new CsrfMiddleware()]);
            $router->get('/products/{id}/edit', fn (Request $r) => $productController->showEdit($r), [new RbacMiddleware('products.edit')]);
            $router->post('/products/bulk-delete', fn (Request $r) => $productController->bulkDelete($r), [new RbacMiddleware('products.delete'), new CsrfMiddleware()]);
            $router->post('/products/{id}', fn (Request $r) => $productController->update($r), [new RbacMiddleware('products.edit'), new CsrfMiddleware()]);
            $router->post('/products/{id}/delete', fn (Request $r) => $productController->delete($r), [new RbacMiddleware('products.delete'), new CsrfMiddleware()]);

            $router->get('/proformas', fn (Request $r) => $proformaController->index($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/create', fn (Request $r) => $proformaController->showCreate($r), [new RbacMiddleware('quotes.create')]);
            $router->post('/proformas', fn (Request $r) => $proformaController->create($r), [new RbacMiddleware('quotes.create'), new CsrfMiddleware()]);
            $router->get('/proformas/{id}', fn (Request $r) => $proformaController->show($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/{id}/print', fn (Request $r) => $printController->proformaPrint($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/{id}/pdf', fn (Request $r) => $printController->proformaPdf($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/{id}/edit', fn (Request $r) => $proformaController->showEdit($r), [new RbacMiddleware('quotes.edit')]);
            $router->post('/proformas/bulk-delete', fn (Request $r) => $proformaController->bulkDelete($r), [new RbacMiddleware('quotes.delete'), new CsrfMiddleware()]);
            $router->post('/proformas/{id}', fn (Request $r) => $proformaController->update($r), [new RbacMiddleware('quotes.edit'), new CsrfMiddleware()]);
            $router->post('/proformas/{id}/delete', fn (Request $r) => $proformaController->delete($r), [new RbacMiddleware('quotes.delete'), new CsrfMiddleware()]);
            $router->post('/proformas/{id}/convert', fn (Request $r) => $invoiceController->convertFromProforma($r), [new RbacMiddleware('quotes.convert'), new CsrfMiddleware()]);

            $router->get('/invoices', fn (Request $r) => $invoiceController->index($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/create', fn (Request $r) => $invoiceController->showCreate($r), [new RbacMiddleware('invoices.create')]);
            $router->post('/invoices', fn (Request $r) => $invoiceController->create($r), [new RbacMiddleware('invoices.create'), new CsrfMiddleware()]);
            $router->get('/invoices/{id}', fn (Request $r) => $invoiceController->show($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/{id}/print', fn (Request $r) => $printController->invoicePrint($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/{id}/pdf', fn (Request $r) => $printController->invoicePdf($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/{id}/edit', fn (Request $r) => $invoiceController->showEdit($r), [new RbacMiddleware('invoices.edit')]);
            $router->post('/invoices/bulk-delete', fn (Request $r) => $invoiceController->bulkDelete($r), [new RbacMiddleware('invoices.delete'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}', fn (Request $r) => $invoiceController->update($r), [new RbacMiddleware('invoices.edit'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}/delete', fn (Request $r) => $invoiceController->delete($r), [new RbacMiddleware('invoices.delete'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}/issue', fn (Request $r) => $invoiceController->issue($r), [new RbacMiddleware('invoices.issue'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}/cancel', fn (Request $r) => $invoiceController->cancel($r), [new RbacMiddleware('invoices.cancel'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}/payments', fn (Request $r) => $paymentController->create($r), [new RbacMiddleware('payments.create'), new CsrfMiddleware()]);
            $router->post('/invoices/{id}/credit-notes', fn (Request $r) => $creditNoteController->create($r), [new RbacMiddleware('credit_notes.create'), new CsrfMiddleware()]);

            $router->get('/periods', fn (Request $r) => $periodController->index($r), [new RbacMiddleware('financial_periods.view')]);
            $router->post('/periods', fn (Request $r) => $periodController->create($r), [new RbacMiddleware('financial_periods.close'), new CsrfMiddleware()]);
            $router->post('/periods/{id}/close', fn (Request $r) => $periodController->close($r), [new RbacMiddleware('financial_periods.close'), new CsrfMiddleware()]);
            $router->post('/periods/{id}/reopen', fn (Request $r) => $periodController->reopen($r), [new RbacMiddleware('financial_periods.reopen'), new CsrfMiddleware()]);

            $router->get('/reports', fn (Request $r) => $reportController->index($r), [new RbacMiddleware('financial_reports.view')]);

            // Contract installment schedule (Stage 2). contracts.edit is the Contracts
            // module's key for non-term changes on a contract; InstallmentService
            // re-checks it on the contract record (scope), plus the contract itself.
            $router->post('/contracts/{id}/installments', fn (Request $r) => $installmentController->create($r), [new RbacMiddleware('contracts.edit'), new CsrfMiddleware()]);
            $router->get('/installments/{id}/edit', fn (Request $r) => $installmentController->showEdit($r), [new RbacMiddleware('contracts.edit')]);
            $router->post('/installments/{id}', fn (Request $r) => $installmentController->update($r), [new RbacMiddleware('contracts.edit'), new CsrfMiddleware()]);
            $router->post('/installments/{id}/void', fn (Request $r) => $installmentController->void($r), [new RbacMiddleware('contracts.edit'), new CsrfMiddleware()]);

            // Invoice ↔ project attribution (Stage 3-B). ProjectRevenueService re-checks
            // invoices.edit on the invoice AND projects.edit on the project (record scope).
            $router->post('/projects/{id}/invoices', fn (Request $r) => $projectRevenueController->attach($r), [new RbacMiddleware('invoices.edit'), new CsrfMiddleware()]);
            $router->post('/projects/{id}/invoices/{invoiceId}/detach', fn (Request $r) => $projectRevenueController->detach($r), [new RbacMiddleware('invoices.edit'), new CsrfMiddleware()]);

            // Expenses (Phase 4). ExpenseService re-checks record scope, project rights,
            // periods (locking read, D-P3), corrections (D-P2) and receipts (E6) itself.
            $view = new RbacMiddleware(ExpenseService::VIEW);
            $manage = fn () => new RbacMiddleware(ExpenseService::MANAGE);
            $router->get('/expenses', fn (Request $r) => $expenseController->index($r), [$view]);
            $router->get('/expenses/create', fn (Request $r) => $expenseController->showCreate($r), [$manage()]);
            $router->post('/expenses', fn (Request $r) => $expenseController->create($r), [$manage(), new CsrfMiddleware()]);
            $router->get('/expenses/{id}', fn (Request $r) => $expenseController->show($r), [$view]);
            $router->get('/expenses/{id}/edit', fn (Request $r) => $expenseController->showEdit($r), [$manage()]);
            $router->post('/expenses/{id}', fn (Request $r) => $expenseController->update($r), [$manage(), new CsrfMiddleware()]);
            $router->post('/expenses/{id}/void', fn (Request $r) => $expenseController->void($r), [$manage(), new CsrfMiddleware()]);
            $router->post('/expenses/{id}/corrections', fn (Request $r) => $expenseController->correct($r), [$manage(), new CsrfMiddleware()]);
            $router->post('/expenses/{id}/attachments', fn (Request $r) => $expenseController->uploadReceipt($r), [$manage(), new CsrfMiddleware()]);
            $router->get('/expenses/{id}/attachments/{attachmentId}', fn (Request $r) => $expenseController->downloadReceipt($r), [$view]);
            $router->post('/expenses/{id}/attachments/{attachmentId}/delete', fn (Request $r) => $expenseController->deleteReceipt($r), [$manage(), new CsrfMiddleware()]);

            // Project expense budget (Stage 5). BudgetService re-checks the project (viewable,
            // record scope), finance.budgets.manage + projects.edit on it, and its status.
            $router->get('/projects/{id}/budget', fn (Request $r) => $budgetController->show($r), [new RbacMiddleware(BudgetService::MANAGE)]);
            $router->post('/projects/{id}/budget', fn (Request $r) => $budgetController->save($r), [new RbacMiddleware(BudgetService::MANAGE), new CsrfMiddleware()]);
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
