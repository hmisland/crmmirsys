<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

/**
 * PHASE 1 STATUS: filesystem discovery only.
 *
 * Scans /modules/&#42;/module.json and exposes the parsed manifests.
 * This deliberately does NOT touch the database yet: the `modules`,
 * `organization_modules`, and `user_module_overrides` tables (Phase 0
 * §7) are a Phase 4 deliverable, built alongside the Module Manager UI
 * and the enable/disable/entitlement logic. Phase 1 only needs "does
 * this module exist on disk at all", which is what ModuleMiddleware
 * checks today (see app/Core/Middleware/ModuleMiddleware.php).
 *
 * No modules exist yet as of Phase 1 (per the roadmap, CRM Core is
 * Phase 5 and Sales is Phase 6 onward) — this class will report an
 * empty list until then, which is expected, not a bug.
 */
final class ModuleRegistry
{
    private static ?self $instance = null;

    /** @var array<string, ModuleManifest> keyed by module key */
    private array $manifests = [];

    private function __construct(private readonly string $modulesPath)
    {
        $this->discover();
    }

    public static function bootstrap(string $modulesPath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($modulesPath);
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('ModuleRegistry::bootstrap() must run before ModuleRegistry::instance().');
        }

        return self::$instance;
    }

    private function discover(): void
    {
        $manifestFiles = glob(rtrim($this->modulesPath, '/') . '/*/module.json') ?: [];

        foreach ($manifestFiles as $file) {
            $manifest = ModuleManifest::fromFile($file);
            $this->manifests[$manifest->key] = $manifest;
        }
    }

    public function isInstalled(string $key): bool
    {
        return isset($this->manifests[$key]);
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->manifests[$key] ?? null;
    }

    /** @return ModuleManifest[] */
    public function all(): array
    {
        return array_values($this->manifests);
    }

    /**
     * Per Phase 0 §8: a dependency must itself be installed. Returns
     * the list of unmet dependency keys (empty = valid). Does not
     * consider *enabled* state (Phase 4), only installed/discovered.
     *
     * @return string[]
     */
    public function unmetDependencies(string $key): array
    {
        $manifest = $this->get($key);
        if ($manifest === null) {
            return [];
        }

        return array_values(array_filter(
            $manifest->dependencies,
            fn (string $dep) => !$this->isInstalled($dep)
        ));
    }
}
