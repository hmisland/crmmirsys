<?php

declare(strict_types=1);

namespace Modules\CustomerPortal;

use App\Application\Services\SmsService;
use App\Core\Container;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerPortal\Controllers\CustomerAuthController;
use Modules\CustomerPortal\Controllers\CustomerPortalController;
use Modules\CustomerPortal\Services\CustomerAuthService;
use Modules\CustomerPortal\Services\OtpDelivery;
use Modules\CustomerPortal\Services\PlatformOtpDelivery;

/**
 * Customer Portal (module key "customer_portal") — V1, read-only: a CRM
 * contact signs in with a one-time code and sees their own profile,
 * surveys, invoices and proformas.
 *
 * Kept apart from staff sign-in: no `users` row, no CurrentUser, no RBAC;
 * its own session keys (CustomerSession), middleware
 * (CustomerAuthMiddleware), code table (customer_portal_otps) and routes:
 *
 *   /customer/login                       which organization? (neutral)
 *   /customer/login/{slug}                email or mobile → code
 *   /customer/login/{slug}/national-id    national ID → masked destinations
 *   /customer/login/{slug}/choose         POST: the chosen destination
 *   /customer/login/{slug}/verify         the code
 *   /customer/login/{slug}/resend         POST: another code
 *   /customer, /customer/profile, /customer/surveys[/{id}],
 *   /customer/invoices[/{id}], /customer/proformas[/{id}], POST /customer/logout
 *
 * The organization must hold this module (Super Admin entitlement);
 * surveys also need Customer Satisfaction, invoices / proformas Finance.
 */
final class CustomerPortalModule implements ModuleInterface
{
    public const KEY = 'customer_portal';

    private ?Container $container = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        $this->container = $container;
        $langPath = dirname(__DIR__, 2) . '/lang';
        // Lazy, so a test (or another transport) can bind its own OtpDelivery before first use.
        if (!$container->has(OtpDelivery::class)) {
            $container->singleton(OtpDelivery::class, fn (Container $c) => new PlatformOtpDelivery($c->make(MailerInterface::class), $c->make(SmsService::class), $langPath));
        }
        $container->singleton(CustomerPortalRepository::class, fn () => new CustomerPortalRepository());
        $container->singleton(CustomerRecordsRepository::class, fn () => new CustomerRecordsRepository());
        $container->singleton(CustomerAuthService::class, fn (Container $c) => new CustomerAuthService(
            $c->make(CustomerPortalRepository::class),
            $c->make(OtpDelivery::class),
            new ActionRateLimiter(),
            $c->make(OrganizationEntitlements::class),
            $c->make(LoggerInterface::class),
        ));
    }

    public function routes(Router $router): void
    {
        $c = $this->container;
        if ($c === null) {
            return;
        }
        $langPath = dirname(__DIR__, 2) . '/lang';
        $auth = fn (): CustomerAuthController => new CustomerAuthController($c->make(TemplateEngine::class), $langPath, $c->make(CustomerAuthService::class));
        $portal = fn (): CustomerPortalController => new CustomerPortalController($c->make(TemplateEngine::class), $langPath, $c->make(CustomerRecordsRepository::class), $c->make(OrganizationEntitlements::class), $c);
        $csrf = new CsrfMiddleware();

        // Sign-in (no staff login involved).
        $router->get('/customer/login', fn (Request $r) => $auth()->neutral($r));
        $router->get('/customer/login/{slug}', fn (Request $r) => $auth()->showLogin($r));
        $router->post('/customer/login/{slug}', fn (Request $r) => $auth()->login($r), [$csrf]);
        $router->get('/customer/login/{slug}/national-id', fn (Request $r) => $auth()->showNationalId($r));
        $router->post('/customer/login/{slug}/national-id', fn (Request $r) => $auth()->nationalId($r), [$csrf]);
        $router->post('/customer/login/{slug}/choose', fn (Request $r) => $auth()->choose($r), [$csrf]);
        $router->get('/customer/login/{slug}/verify', fn (Request $r) => $auth()->showVerify($r));
        $router->post('/customer/login/{slug}/verify', fn (Request $r) => $auth()->verify($r), [$csrf]);
        $router->post('/customer/login/{slug}/resend', fn (Request $r) => $auth()->resend($r), [$csrf]);

        // The signed-in customer's own pages (read-only; a survey answer is the only submission).
        $customer = new CustomerAuthMiddleware($c->make(CustomerPortalRepository::class), $c->make(OrganizationEntitlements::class));
        $router->post('/customer/logout', fn (Request $r) => $auth()->logout($r), [$csrf]);
        $router->get('/customer', fn (Request $r) => $portal()->home($r), [$customer]);
        $router->get('/customer/profile', fn (Request $r) => $portal()->profile($r), [$customer]);
        $router->get('/customer/surveys', fn (Request $r) => $portal()->surveys($r), [$customer]);
        $router->get('/customer/surveys/{id}', fn (Request $r) => $portal()->survey($r), [$customer]);
        $router->post('/customer/surveys/{id}', fn (Request $r) => $portal()->answerSurvey($r), [$customer, $csrf]);
        $router->get('/customer/invoices', fn (Request $r) => $portal()->invoices($r), [$customer]);
        $router->get('/customer/invoices/{id}', fn (Request $r) => $portal()->invoice($r), [$customer]);
        $router->get('/customer/proformas', fn (Request $r) => $portal()->proformas($r), [$customer]);
        $router->get('/customer/proformas/{id}', fn (Request $r) => $portal()->proforma($r), [$customer]);
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
