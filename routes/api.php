<?php

declare(strict_types=1);

use App\Application\Controllers\Api\AuthApiController;
use App\Application\Controllers\Api\ContactApiController;
use App\Application\Controllers\Api\TokenApiController;
use App\Application\Services\ApiTokenService;
use App\Application\Services\AuthService;
use App\Application\Services\ContactService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\RbacMiddleware;
use App\Core\Middleware\RequireOrganizationMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Security\ActionRateLimiter;

/**
 * @var Router $router
 * @var Container $container
 */

$authApi = new AuthApiController(
    $container->make(AuthService::class),
    $container->make(ApiTokenService::class)
);
$tokenApi = new TokenApiController(
    $container->make(AuthService::class),
    $container->make(ApiTokenService::class),
    new ActionRateLimiter()
);

$contactApi = new ContactApiController(
    $container->make(ContactService::class),
    new CompanyRepository(),
    new ContactCategoryRepository()
);

$router->group('/api/v1', [], function (Router $router) use ($container, $authApi, $tokenApi, $contactApi) {
    // Liveness/readiness probe — also useful to confirm DB config is
    // wired correctly during cPanel deployment (Phase 18), without
    // exposing anything sensitive.
    $router->get('/health', function (Request $request) {
        return Response::json([
            'data' => [
                'status' => 'ok',
                'time' => date(DATE_ATOM),
            ],
        ]);
    });

    // Temporary Phase 1 diagnostic: lists modules discovered on disk
    // via ModuleRegistry. Has no auth/RBAC yet (both are Phase 2/3),
    // so it deliberately reveals nothing beyond module keys/versions —
    // remove or move behind Super-Admin-only auth once Phase 4's
    // real Module Manager endpoints exist.
    $router->get('/modules', function (Request $request) {
        $modules = array_map(
            fn ($m) => [
                'key' => $m->key,
                'name' => $m->name,
                'version' => $m->version,
                'type' => $m->type,
                'is_core' => $m->isCore,
                'dependencies' => $m->dependencies,
            ],
            ModuleRegistry::instance()->all()
        );

        return Response::json(['data' => $modules]);
    });

    // --- Auth (Phase 2) ---------------------------------------------
    // Same cookie session as the web app — see AuthApiController's
    // docblock for why, and what a future token-based option would add.
    $router->group('/auth', [], function (Router $router) use ($authApi, $tokenApi) {
        $router->post('/register', fn (Request $r) => $authApi->register($r));
        $router->post('/login', fn (Request $r) => $authApi->login($r));
        $router->post('/email/verify', fn (Request $r) => $authApi->verifyEmail($r));
        $router->post('/password/forgot', fn (Request $r) => $authApi->forgotPassword($r));
        $router->post('/password/reset', fn (Request $r) => $authApi->resetPassword($r));

        // Bearer tokens for non-browser clients (Phase 0 multi-client API).
        // Issuance is public (credentials in the body); revocation needs the token.
        $router->post('/token', fn (Request $r) => $tokenApi->issue($r));
        $router->delete('/token', fn (Request $r) => $tokenApi->revoke($r), [new AuthMiddleware()]);

        // CSRF token for cookie-session fetch/XHR clients (PWA).
        $router->get('/csrf', fn (Request $r) => $authApi->csrf($r));

        // Cookie-session writes carry CSRF protection (token in `_csrf` or the
        // X-CSRF-Token header); a request authenticated by a valid Bearer
        // token is not cookie-authenticated and is exempt (CsrfMiddleware).
        $router->post('/logout', fn (Request $r) => $authApi->logout($r), [new AuthMiddleware(), new CsrfMiddleware()]);
        $router->get('/me', fn (Request $r) => $authApi->me($r), [new AuthMiddleware()]);
        $router->patch('/profile', fn (Request $r) => $authApi->updateProfile($r), [new AuthMiddleware(), new CsrfMiddleware()]);
    });

    // --- Contacts (Phase 1, read-only) -------------------------------
    // Same service + `contacts.view` + organization scoping as the web
    // pages; a Super Admin (no organization) gets 404, see
    // RequireOrganizationMiddleware.
    $router->group('/contacts', [new AuthMiddleware(), new RequireOrganizationMiddleware()], function (Router $router) use ($contactApi) {
        $router->get('', fn (Request $r) => $contactApi->index($r), [new RbacMiddleware('contacts.view')]);
        $router->get('/{id}', fn (Request $r) => $contactApi->show($r), [new RbacMiddleware('contacts.view')]);
    });
});
