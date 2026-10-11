<?php

declare(strict_types=1);

namespace Modules\Notification;

use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\NotificationPlatformSettingsRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Repositories\WalletPlatformSettingsRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Notification\Controllers\NotificationController;
use Modules\Notification\Controllers\NotificationPlatformController;
use Modules\Notification\Gateways\BaleGateway;
use Modules\Notification\Gateways\SmsServiceGateway;
use App\Application\Services\SmsService;
use Modules\Notification\Services\NotificationCreditService;
use Modules\Notification\Services\NotificationDispatchService;
use Modules\Notification\Services\NotificationRecipientService;
use Modules\Notification\Services\NotificationSettingsService;
use Modules\Notification\Services\NotificationSmsBilling;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Services\WalletSettingsService;
use Modules\Wallet\WalletLedgerRepository;
use Modules\Wallet\WalletOrgSettingsRepository;
use Modules\Wallet\WalletRepository;

final class NotificationModule implements ModuleInterface
{
    private ?NotificationController $notificationController = null;
    private ?NotificationPlatformController $platformController = null;
    private ?NotificationDispatchService $dispatchService = null;
    private ?NotificationRecipientService $recipientService = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    /** Exposed so Internal Mail's compose/reply flow can trigger a notification without a circular module dependency. */
    public function dispatchService(): ?NotificationDispatchService
    {
        return $this->dispatchService;
    }

    /** Exposed so the Core Profile page can optionally show/edit a user's Bale/Telegram chat ID without hard-depending on this module — same reasoning as dispatchService(). */
    public function recipientService(): ?NotificationRecipientService
    {
        return $this->recipientService;
    }

    public function register(Container $container): void
    {
        $authorization = $container->make(AuthorizationEngine::class);

        // Notification depends on Wallet (module.json "dependencies")
        // — both modules are always wired regardless of per-org
        // enablement (see Sales/Support's own bootstrap for the same
        // pattern), so constructing Wallet's services here directly
        // is safe; ModuleMiddleware is what actually enforces that an
        // org can't USE this module without Wallet also being granted.
        $walletService = new WalletService(new WalletRepository(), new WalletLedgerRepository(), new CurrencyRepository(), $authorization);
        $walletSettingsService = new WalletSettingsService(
            new WalletPlatformSettingsRepository(),
            new WalletOrgSettingsRepository(),
            new CurrencyRepository(),
            $authorization
        );

        $creditService = new NotificationCreditService(
            new NotificationCreditRepository(),
            new NotificationCreditTransactionRepository(),
            $walletService,
            $walletSettingsService,
            $authorization
        );

        $recipientService = new NotificationRecipientService(new NotificationRecipientRepository());
        $this->recipientService = $recipientService;
        $settingsService = new NotificationSettingsService(new NotificationPlatformSettingsRepository());

        $baleConfig = (array) Config::get('notifications.bale', []);
        $bale = new BaleGateway((string) ($baleConfig['bot_token'] ?? ''));
        // SMS goes through the core SmsService (Kavenegar). This module
        // also pays for every platform-account SMS in the app, from the
        // organization's prepaid credit.
        $smsService = $container->make(SmsService::class);
        $smsService->setBilling(new NotificationSmsBilling($creditService, new NotificationPlatformSettingsRepository()));
        $sms = new SmsServiceGateway($smsService);

        $this->dispatchService = new NotificationDispatchService(
            new NotificationRecipientRepository(),
            $creditService,
            new NotificationLogRepository(),
            new NotificationPlatformSettingsRepository(),
            $bale,
            $sms,
            new UserRepository()
        );

        $templates = $container->make(TemplateEngine::class);

        $this->notificationController = new NotificationController(
            $templates,
            $recipientService,
            $creditService,
            $this->dispatchService,
            $container->make(LoggerInterface::class)
        );
        $this->platformController = new NotificationPlatformController($templates, $settingsService);
    }

    public function routes(Router $router): void
    {
        $auth = new AuthMiddleware();
        $module = new ModuleMiddleware('notifications');
        $notificationController = $this->notificationController;
        $platformController = $this->platformController;

        $router->group('/notifications', [$auth, $module], function (Router $router) use ($notificationController) {
            $router->get('/settings', fn (Request $r) => $notificationController->showSettings($r));
            $router->post('/settings', fn (Request $r) => $notificationController->saveSettings($r), [new CsrfMiddleware()]);
            $router->post('/settings/test-bale', fn (Request $r) => $notificationController->testBale($r), [new CsrfMiddleware()]);
            $router->get('/admin', fn (Request $r) => $notificationController->admin($r));
            $router->post('/admin/charge', fn (Request $r) => $notificationController->charge($r), [new CsrfMiddleware()]);
        });

        // Super Admin platform pricing — not gated by ModuleMiddleware,
        // same reasoning as Wallet's own platform settings: it must be
        // reachable before any org has this module enabled at all.
        $router->get('/admin/notifications/settings', fn (Request $r) => $platformController->index($r), [$auth]);
        $router->post('/admin/notifications/settings', fn (Request $r) => $platformController->update($r), [$auth, new CsrfMiddleware()]);
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
