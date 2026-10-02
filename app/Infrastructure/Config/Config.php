<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Loads every array returned by config/*.php into a single namespaced
 * tree (`app`, `database`, `session`, ...) and exposes dot-notation
 * access, e.g. Config::get('database.connections.mysql.host').
 */
final class Config
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $items = [];

    private function __construct(string $configPath)
    {
        foreach (glob(rtrim($configPath, '/') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            /** @noinspection PhpIncludeInspection */
            $this->items[$key] = require $file;
        }
    }

    public static function bootstrap(string $configPath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($configPath);
        }

        return self::$instance;
    }

    private static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Config::bootstrap() must run before Config::get().');
        }

        return self::$instance;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::instance()->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

/**
 * Small env() helper used inside config/*.php files. Kept here so
 * config files stay framework-free and easy to read.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    return match (strtolower($value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}
