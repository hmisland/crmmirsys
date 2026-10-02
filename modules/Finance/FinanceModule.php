<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CountryRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\TaxJurisdictionRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Finance\Controllers\CreditNoteController;
use Modules\Finance\Controllers\FinancialPeriodController;
use Modules\Finance\Controllers\FinanceSettingsController;
use Modules\Finance\Controllers\InvoiceController;
use Modules\Finance\Controllers\PaymentController;
use Modules\Finance\Controllers\ProductController;
use Modules\Finance\Controllers\ProformaController;
use Modules\Finance\Controllers\ReportController;
use Modules\Finance\Services\CreditNoteService;
use Modules\Finance\Services\FinancialPeriodService;
use Modules\Finance\Services\FinancialProfileService;
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
    private ?ProductService $productServiceInstance = null;
    private ?ProformaService $proformaServiceInstance = null;
    private ?InvoiceService $invoiceServiceInstance = null;

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

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);
        $currencies = new CurrencyRepository();
        $taxProfiles = new TaxProfileRepository();
        $financialProfiles = new FinancialProfileRepository();
        $contacts = new ContactRepository();
        $templates = $container->make(TemplateEngine::class);
        $taxCalculation = new TaxCalculationService(new TaxRuleRepository());
        $financialPeriods = new FinancialPeriodService(new FinancialPeriodRepository(), $authorization);

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

        $proformaRepo = new ProformaRepository();
        $proformaItemRepo = new ProformaItemRepository();

        $proformaService = new ProformaService(
            $proformaRepo,
            $proformaItemRepo,
            $financialProfiles,
            $taxCalculation,
            $authorization
        );
        $this->proformaServiceInstance = $proformaService;

        $this->proformaController = new ProformaController(
            $templates,
            $proformaService,
            $contacts,
            $currencies,
            $taxProfiles,
            new ProductRepository(),
            $financialProfiles
        );

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
            $financialPeriods
        );
        $this->invoiceServiceInstance = $invoiceService;

        $paymentService = new PaymentService(new PaymentRepository(), $invoiceRepo, $creditNoteRepo, $authorization, $financialPeriods);

        $creditNoteService = new CreditNoteService(
            $creditNoteRepo,
            new CreditNoteNumberSeriesRepository(),
            $invoiceRepo,
            $authorization,
            $financialPeriods
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
            $creditNoteService
        );

        $this->paymentController = new PaymentController($templates, $paymentService);
        $this->creditNoteController = new CreditNoteController($templates, $creditNoteService);
        $this->periodController = new FinancialPeriodController($templates, $financialPeriods);
        $this->reportController = new ReportController($templates, new ReportService($invoiceRepo, $creditNoteRepo, $authorization));
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

        $router->group('/finance', [$auth, $module], function (Router $router) use ($settingsController, $productController, $proformaController, $invoiceController, $paymentController, $creditNoteController, $periodController, $reportController) {
            $router->get('/settings', fn (Request $r) => $settingsController->show($r), [new RbacMiddleware('finance.settings.view')]);
            $router->post('/settings', fn (Request $r) => $settingsController->update($r), [new CsrfMiddleware()]);

            $router->get('/products', fn (Request $r) => $productController->index($r), [new RbacMiddleware('products.view')]);
            $router->get('/products/create', fn (Request $r) => $productController->showCreate($r), [new RbacMiddleware('products.create')]);
            $router->post('/products', fn (Request $r) => $productController->create($r), [new RbacMiddleware('products.create'), new CsrfMiddleware()]);
            $router->get('/products/{id}/edit', fn (Request $r) => $productController->showEdit($r), [new RbacMiddleware('products.edit')]);
            $router->post('/products/{id}', fn (Request $r) => $productController->update($r), [new RbacMiddleware('products.edit'), new CsrfMiddleware()]);
            $router->post('/products/{id}/delete', fn (Request $r) => $productController->delete($r), [new RbacMiddleware('products.delete'), new CsrfMiddleware()]);

            $router->get('/proformas', fn (Request $r) => $proformaController->index($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/create', fn (Request $r) => $proformaController->showCreate($r), [new RbacMiddleware('quotes.create')]);
            $router->post('/proformas', fn (Request $r) => $proformaController->create($r), [new RbacMiddleware('quotes.create'), new CsrfMiddleware()]);
            $router->get('/proformas/{id}', fn (Request $r) => $proformaController->show($r), [new RbacMiddleware('quotes.view')]);
            $router->get('/proformas/{id}/edit', fn (Request $r) => $proformaController->showEdit($r), [new RbacMiddleware('quotes.edit')]);
            $router->post('/proformas/{id}', fn (Request $r) => $proformaController->update($r), [new RbacMiddleware('quotes.edit'), new CsrfMiddleware()]);
            $router->post('/proformas/{id}/delete', fn (Request $r) => $proformaController->delete($r), [new RbacMiddleware('quotes.delete'), new CsrfMiddleware()]);
            $router->post('/proformas/{id}/convert', fn (Request $r) => $invoiceController->convertFromProforma($r), [new RbacMiddleware('quotes.convert'), new CsrfMiddleware()]);

            $router->get('/invoices', fn (Request $r) => $invoiceController->index($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/create', fn (Request $r) => $invoiceController->showCreate($r), [new RbacMiddleware('invoices.create')]);
            $router->post('/invoices', fn (Request $r) => $invoiceController->create($r), [new RbacMiddleware('invoices.create'), new CsrfMiddleware()]);
            $router->get('/invoices/{id}', fn (Request $r) => $invoiceController->show($r), [new RbacMiddleware('invoices.view')]);
            $router->get('/invoices/{id}/edit', fn (Request $r) => $invoiceController->showEdit($r), [new RbacMiddleware('invoices.edit')]);
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
        });
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
