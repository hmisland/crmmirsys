<?php

declare(strict_types=1);

namespace Tests\Lite\Fixtures;

use App\Core\Container;
use App\Core\Router;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Modules\ModuleInterface;
use App\Infrastructure\Modules\ModuleManifest;
use App\Infrastructure\Modules\ProvidesServices;

/**
 * Test-only stand-ins. "widgets" is a made-up module key — these are
 * NOT the future Contracts/Projects modules.
 */
interface WidgetCatalog
{
    public function names(): array;
}

final class WidgetService implements WidgetCatalog
{
    public function __construct(public readonly string $label = 'first')
    {
    }

    public function names(): array
    {
        return ['a', 'b'];
    }
}

final class UnrelatedService
{
}

abstract class FakeModuleBase implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        throw new \LogicException('not used by these tests');
    }

    public function register(Container $container): void
    {
    }

    public function routes(Router $router): void
    {
    }

    public function migrationsPath(): string
    {
        return __DIR__;
    }
}

/** Implements ProvidesServices; services() returns whatever the test set. */
final class FakeProvidingModule extends FakeModuleBase implements ProvidesServices
{
    /** @param array<mixed, mixed> $services */
    public function __construct(private readonly array $services)
    {
    }

    public function services(): array
    {
        return $this->services;
    }
}

/** An existing-style module: no ProvidesServices. */
final class FakePlainModule extends FakeModuleBase
{
}

final class FakeGate
{
    /**
     * A ModuleGate with no database: $enabledModules are available,
     * $grants are the permissions every user holds; $calls counts
     * module lookups (to verify memoization).
     *
     * @param string[] $enabledModules
     * @param string[] $grants
     */
    public static function make(array $enabledModules, array $grants, ?\ArrayObject $calls = null): ModuleGate
    {
        return new ModuleGate(
            function (string $module, array $user) use ($enabledModules, $calls): bool {
                if ($calls !== null) {
                    $calls[] = $module;
                }

                return in_array($module, $enabledModules, true);
            },
            fn (array $user, string $permission): bool => in_array($permission, $grants, true),
        );
    }
}
