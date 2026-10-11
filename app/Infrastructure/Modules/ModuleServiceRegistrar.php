<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Core\Container;
use App\Infrastructure\Logging\LoggerInterface;

/**
 * Binds what a ProvidesServices module returns. Deterministic and safe:
 *
 * - first registration wins: an abstract that is already bound (by
 *   Core or by an earlier module — discovery order is alphabetical by
 *   folder) is skipped, so a module can never replace a Core service
 *   such as AuthorizationEngine;
 * - an entry whose key is not a class/interface, or whose value is not
 *   an instance of it, is skipped;
 * - null values are skipped silently (module chose not to expose it).
 *
 * Skips are logged as warnings, never thrown: one misbehaving module
 * must not take the whole application down on shared hosting.
 */
final class ModuleServiceRegistrar
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * Called by Application for every bootstrapped module: a module that
     * doesn't implement ProvidesServices is left alone.
     *
     * @return string[] the abstracts actually bound
     */
    public function registerModule(Container $container, string $moduleKey, object $module): array
    {
        return $module instanceof ProvidesServices
            ? $this->register($container, $moduleKey, $module->services())
            : [];
    }

    /**
     * @param array<mixed, mixed> $services
     * @return string[] the abstracts actually bound
     */
    public function register(Container $container, string $moduleKey, array $services): array
    {
        $bound = [];

        foreach ($services as $abstract => $instance) {
            if ($instance === null) {
                continue;
            }

            if (!is_string($abstract) || !(class_exists($abstract) || interface_exists($abstract)) || !($instance instanceof $abstract)) {
                $this->logger?->warning('Module service skipped: invalid entry', ['module' => $moduleKey, 'service' => is_string($abstract) ? $abstract : gettype($abstract)]);
                continue;
            }

            if ($container->has($abstract)) {
                $this->logger?->warning('Module service skipped: already bound', ['module' => $moduleKey, 'service' => $abstract]);
                continue;
            }

            $container->instance($abstract, $instance);
            $bound[] = $abstract;
        }

        return $bound;
    }
}
