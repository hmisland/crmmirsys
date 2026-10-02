<?php

declare(strict_types=1);

namespace Modules\Demo;

use App\Core\Container;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\ModuleMiddleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Not a real CRM feature — this exists solely to prove the Phase 4
 * Module Manager pipeline end to end: filesystem discovery (Phase 1)
 * -> DB sync -> Super Admin global enable/disable -> Org Admin
 * per-organization enable/disable -> ModuleMiddleware's full
 * resolution chain -> a per-module migration
 * (Migrations/0001_create_demo_notes_table.php) -> a module owning
 * its own data via its own Repository (DemoNoteRepository) -> tenant
 * isolation still applying inside a module (DemoNoteRepository
 * extends TenantScopedRepository same as any Core repository would).
 *
 * Delete this whole /modules/Demo/ folder once a real module (Sales,
 * Phase 6+) exists to serve as the reference example instead — it
 * has served its purpose once someone confirms the toggle/dependency/
 * migration flow actually works on the live site.
 *
 * Known simplification: renders through the default theme's own
 * templates/demo.php rather than a module-owned Templates/ folder
 * (Phase 0 §63 sketches modules having their own Templates/) —
 * teaching TemplateEngine/ThemeLoader to also search a module's own
 * template directory is a real feature, just not built in this
 * minimal proof-of-pipeline module.
 */
final class DemoModule implements ModuleInterface
{
    private ?Container $container = null;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::fromFile(__DIR__ . '/module.json');
    }

    public function register(Container $container): void
    {
        $this->container = $container;
    }

    public function routes(Router $router): void
    {
        $moduleGate = new ModuleMiddleware('demo');
        $auth = new AuthMiddleware();
        $container = $this->container;

        $router->get('/demo', function (Request $request) use ($container) {
            /** @var TemplateEngine $templates */
            $templates = $container->make(TemplateEngine::class);
            $notes = (new DemoNoteRepository())->all();

            return Response::html($templates->renderWithLayout('demo', 'layout', ['notes' => $notes]));
        }, [$auth, $moduleGate]);

        $router->post('/demo', function (Request $request) {
            (new DemoNoteRepository())->create((string) $request->input('body', ''));

            return Response::redirect('/demo');
        }, [$auth, $moduleGate, new CsrfMiddleware()]);
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/Migrations';
    }
}
