<?php

declare(strict_types=1);

use App\Application\Controllers\Api\AuthApiController;
use App\Application\Services\AuthService;
use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Infrastructure\Modules\ModuleRegistry;

/**
 * @var Router $router
 * @var Container $container
 */

$authApi = new AuthApiController($container->make(AuthService::class));

$router->group('/api/v1', [], function (Router $router) use ($container, $authApi) {
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
    $router->group('/auth', [], function (Router $router) use ($authApi) {
        $router->post('/register', fn (Request $r) => $authApi->register($r));
        $router->post('/login', fn (Request $r) => $authApi->login($r));
        $router->post('/email/verify', fn (Request $r) => $authApi->verifyEmail($r));
        $router->post('/password/forgot', fn (Request $r) => $authApi->forgotPassword($r));
        $router->post('/password/reset', fn (Request $r) => $authApi->resetPassword($r));

        $router->post('/logout', fn (Request $r) => $authApi->logout($r), [new AuthMiddleware()]);
        $router->get('/me', fn (Request $r) => $authApi->me($r), [new AuthMiddleware()]);
        $router->patch('/profile', fn (Request $r) => $authApi->updateProfile($r), [new AuthMiddleware()]);
    });
});
