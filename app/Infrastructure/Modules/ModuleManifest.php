<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

/**
 * Parsed representation of one module's module.json, per Phase 0 §63:
 *
 *   {
 *     "key": "sales",
 *     "name": "Sales",
 *     "version": "1.0.0",
 *     "type": "optional",
 *     "is_core": false,
 *     "dependencies": ["core"],
 *     "bootstrap": "Modules\\Sales\\SalesModule"
 *   }
 *
 * "bootstrap" (Phase 4, optional) names the class implementing
 * ModuleInterface that Application::loadRoutes() instantiates to call
 * register()/routes() — a module with no routes/services of its own
 * (rare, but not impossible) can omit it entirely.
 */
final class ModuleManifest
{
    /**
     * @param string[] $dependencies module keys this module declares it needs
     */
    private function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly string $type, // core|optional|addon|future
        public readonly bool $isCore,
        public readonly array $dependencies,
        public readonly string $path,
        public readonly ?string $bootstrapClass,
    ) {
    }

    public static function fromFile(string $manifestPath): self
    {
        $json = json_decode((string) file_get_contents($manifestPath), true);

        if (!is_array($json) || empty($json['key'])) {
            throw new \RuntimeException("Invalid module.json at {$manifestPath}");
        }

        return new self(
            key: (string) $json['key'],
            name: (string) ($json['name'] ?? $json['key']),
            version: (string) ($json['version'] ?? '0.1.0'),
            type: (string) ($json['type'] ?? 'optional'),
            isCore: (bool) ($json['is_core'] ?? false),
            dependencies: array_map('strval', $json['dependencies'] ?? []),
            path: dirname($manifestPath),
            bootstrapClass: isset($json['bootstrap']) ? (string) $json['bootstrap'] : null,
        );
    }
}
