<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Core\Container;
use App\Core\Router;

/**
 * A module's own bootstrap class (e.g. Modules\Sales\SalesModule)
 * implements this. No module exists yet as of Phase 1 — this is the
 * contract Phase 4 (Module Manager) and Phase 5+ (actual modules) build
 * against, defined now so ModuleRegistry has something concrete to
 * eventually instantiate rather than being redesigned later.
 */
interface ModuleInterface
{
    public function manifest(): ModuleManifest;

    /**
     * Bind the module's own services into the container. Called for
     * every *installed* module, regardless of enabled state.
     */
    public function register(Container $container): void;

    /**
     * Register the module's routes. Only called once Phase 4's
     * ModuleMiddleware-backed enable check is wired to the route group.
     */
    public function routes(Router $router): void;

    /**
     * Absolute path to this module's own Migrations directory, for use
     * with App\Infrastructure\Database\Migrator.
     */
    public function migrationsPath(): string;
}
