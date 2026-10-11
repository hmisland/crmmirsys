<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

/**
 * Optional capability for a module's bootstrap class: expose services
 * other code may *optionally* use, without Core naming the module.
 * Application calls services() right after register() and binds each
 * entry into the container (see ModuleServiceRegistrar for the rules).
 *
 * This replaces, for new modules, the per-module `instanceof` blocks in
 * Application::bootstrapModuleRoutes(). Modules that don't implement
 * it need no change.
 *
 * Binding a service does NOT grant access to it: services are bound for
 * every installed module regardless of entitlement, exactly like the
 * existing ones. A caller must still check the module is available for
 * the user (ModuleGate / ModuleAccessResolver) and the permission —
 * the ClientRecords\Services\ClientIntegrations pattern.
 */
interface ProvidesServices
{
    /**
     * @return array<class-string, object|null> service class/interface
     *         => the instance built in register(); null = not exposed
     *         (e.g. the module chose not to build it)
     */
    public function services(): array;
}
