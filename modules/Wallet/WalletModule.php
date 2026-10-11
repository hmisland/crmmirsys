<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Application\Services\BankAccountService;
use App\Application\Services\BankService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\WalletPlatformSettingsRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Wallet\Controllers\WalletAdminController;
use Modules\Wallet\Controllers\WalletGiftController;
use Modules\Wallet\Services\WalletGiftService;
use App\Infrastructure\Repositories\OrganizationRepository;
use Modules\Wallet\Controllers\WalletController;
use Modules\Wallet\Controllers\WalletPlatformController;
use Modules\Wallet\Gateways\GatewayRegistry;
use Modules\Wallet\Gateways\ZarinPalGateway;
use Modules\Wallet\Services\WalletAdminService;
use Modules\Wallet\Services\WalletLedgerAccessService;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Services\WalletSettingsService;
use Modules\Wallet\Services\WalletTopUpService;
use Modules\Wallet\Services\WalletTransferService;
use Modules\Wallet\Services\WalletWithdrawalService;

final class WalletModule implements ModuleInterface
{
    private ?WalletController $walletController = null;
    private ?WalletAdminController $adminController = null;
    private ?WalletPlatformController $platformController = null;
    private ?WalletGiftController $giftController = null;
    private ?WalletService $walletServiceInstance = null;
    private ?WalletSettingsService $walletSettingsServiceInstance = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Core (the Dashboard) can optionally read a balance without hard-depending on this module — same lazy pattern as Notification. */
    public function walletService(): ?WalletService
    {
        return $this->walletServiceInstance;
    }

    /** Exposed for the same reason — the Dashboard needs the org's wallet currency, not just its balance. */
    public function walletSettingsService(): ?WalletSettingsService
    {
        return $this->walletSettingsServiceInstance;
    }

    public function register(Container $container): void
    {
        $walletRepo = new WalletRepository();
        $ledgerRepo = new WalletLedgerRepository();
        $transactionsRepo = new WalletTransactionRepository();
        $withdrawalsRepo = new WalletWithdrawalRepository();
        $orgSettingsRepo = new WalletOrgSettingsRepository();
        $currencies = new CurrencyRepository();
        $users = new UserRepository();
        $authorization = $container->make(AuthorizationEngine::class);

        $walletService = new WalletService($walletRepo, $ledgerRepo, $currencies, $authorization);
        $this->walletServiceInstance = $walletService;

        $settingsService = new WalletSettingsService(
            new WalletPlatformSettingsRepository(),
            $orgSettingsRepo,
            $currencies,
            $authorization
        );
        $this->walletSettingsServiceInstance = $settingsService;

        $gateways = new GatewayRegistry();
        $zarinpalConfig = (array) Config::get('wallet.zarinpal', []);
        $gateways->register(new ZarinPalGateway(
            (string) ($zarinpalConfig['merchant_id'] ?? ''),
            (bool) ($zarinpalConfig['sandbox'] ?? true)
        ));

        $topUpService = new WalletTopUpService($transactionsRepo, $walletService, $settingsService, $gateways, $authorization);
        $transferService = new WalletTransferService($walletService, $settingsService, $orgSettingsRepo, $users, $authorization);
        $withdrawalService = new WalletWithdrawalService($withdrawalsRepo, $walletService, $orgSettingsRepo, $authorization);
        $adminService = new WalletAdminService($walletService, $users, $authorization);
        $ledgerAccess = new WalletLedgerAccessService($ledgerRepo, $authorization);

        $templates = $container->make(TemplateEngine::class);

        $giftService = new WalletGiftService(new WalletGiftCodeRepository(), $walletService, $settingsService, $users, $authorization);

        $this->walletController = new WalletController(
            $templates,
            $walletService,
            $topUpService,
            $transferService,
            $withdrawalService,
            $settingsService,
            $withdrawalsRepo,
            $gateways,
            $users,
            $container->make(BankAccountService::class),
            $giftService
        );

        $this->adminController = new WalletAdminController(
            $templates,
            $withdrawalService,
            $adminService,
            $settingsService,
            $ledgerAccess,
            $users,
            $giftService
        );

        $this->platformController = new WalletPlatformController($templates, $settingsService, $currencies, $container->make(BankService::class));

        $this->giftController = new WalletGiftController($templates, $giftService, new OrganizationRepository());
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('wallet');
        $walletController = $this->walletController;
        $adminController = $this->adminController;
        $platformController = $this->platformController;

        $router->group('/wallet', [$auth, $module], function (Router $router) use ($walletController) {
            $router->get('', fn (Request $r) => $walletController->index($r), [new RbacMiddleware('wallet.view')]);
            $router->post('/topup', fn (Request $r) => $walletController->initiateTopUp($r), [new CsrfMiddleware()]);
            $router->get('/callback/{gateway}', fn (Request $r) => $walletController->callback($r));
            $router->post('/transfer', fn (Request $r) => $walletController->transfer($r), [new CsrfMiddleware()]);
            $router->post('/withdraw', fn (Request $r) => $walletController->requestWithdrawal($r), [new CsrfMiddleware()]);
        });

        $giftController = $this->giftController;
        $router->group('/org/wallet', [$auth, $module], function (Router $router) use ($adminController, $giftController) {
            $router->get('', fn (Request $r) => $adminController->index($r), [new RbacMiddleware('wallet.transactions.view')]);
            $router->post('/withdrawals/{id}/approve', fn (Request $r) => $adminController->approveWithdrawal($r), [new CsrfMiddleware()]);
            $router->post('/withdrawals/{id}/reject', fn (Request $r) => $adminController->rejectWithdrawal($r), [new CsrfMiddleware()]);
            $router->post('/withdrawals/{id}/paid', fn (Request $r) => $adminController->markWithdrawalPaid($r), [new CsrfMiddleware()]);
            $router->post('/adjust', fn (Request $r) => $adminController->adjust($r), [new CsrfMiddleware()]);
            $router->post('/settings', fn (Request $r) => $adminController->updateSettings($r), [new CsrfMiddleware()]);
            // Gift credit (org admin; wallet.adjust checked in WalletGiftService).
            $router->post('/gift/redeem', fn (Request $r) => $giftController->redeem($r), [new CsrfMiddleware()]);
            $router->post('/gift/send', fn (Request $r) => $giftController->send($r), [new CsrfMiddleware()]);
        });

        // Super Admin platform settings are NOT gated by ModuleMiddleware
        // — they must be reachable even for an org that has never
        // enabled Wallet, since Super Admin sets the cap BEFORE any
        // org uses it. Only $auth applies; the controller itself
        // checks is_super_admin directly, same pattern as
        // OrganizationModuleAdminController.
        // Super Admin gift codes (is_super_admin checked in WalletGiftService).
        $giftController = $this->giftController;
        $router->get('/admin/wallet/gift-codes', fn (Request $r) => $giftController->index($r), [$auth]);
        $router->post('/admin/wallet/gift-codes', fn (Request $r) => $giftController->generate($r), [$auth, new CsrfMiddleware()]);
        $router->post('/admin/wallet/gift-codes/{id}/revoke', fn (Request $r) => $giftController->revoke($r), [$auth, new CsrfMiddleware()]);

        $router->get('/admin/wallet/settings', fn (Request $r) => $platformController->index($r), [$auth]);
        $router->post('/admin/wallet/settings', fn (Request $r) => $platformController->update($r), [$auth, new CsrfMiddleware()]);
        $router->post('/admin/wallet/banks', fn (Request $r) => $platformController->addBank($r), [$auth, new CsrfMiddleware()]);
        $router->post('/admin/wallet/banks/{id}/deactivate', fn (Request $r) => $platformController->deactivateBank($r), [$auth, new CsrfMiddleware()]);
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
