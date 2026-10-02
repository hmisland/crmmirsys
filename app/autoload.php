<?php

declare(strict_types=1);

/**
 * Hand-rolled autoloader instead of relying on `vendor/autoload.php`.
 * Per Phase 0 §67 the production target is plain shared cPanel hosting
 * with no SSH access, so `composer install` cannot run on the server
 * itself. Composer MAY still be used in local development (see
 * composer.json) to pull dev-only tooling like PHPUnit, but nothing
 * the running application needs at runtime depends on it.
 */

$prefixes = [
    'App\\' => __DIR__ . '/',
    'Modules\\' => dirname(__DIR__) . '/modules/',
];

spl_autoload_register(function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
            return;
        }
    }
});
