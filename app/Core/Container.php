<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Deliberately small DI container: bind a factory closure, or a ready
 * instance, and resolve it later by class/interface name. No autowiring
 * magic — explicit registration keeps behaviour predictable, which
 * matters more than convenience for a codebase other developers (and
 * eventually a Python port) need to reason about.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $abstract, callable $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function singleton(string $abstract, callable $factory): void
    {
        $this->bind($abstract, function (self $c) use ($factory, $abstract) {
            if (!array_key_exists($abstract, $this->instances)) {
                $this->instances[$abstract] = $factory($c);
            }

            return $this->instances[$abstract];
        });
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->bindings[$abstract] = fn () => $instance;
    }

    public function make(string $abstract): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this);
        }

        // Fall back to a zero-argument constructor for plain classes
        // (controllers, services) that have no explicit binding.
        if (class_exists($abstract)) {
            return new $abstract();
        }

        throw new \RuntimeException("Nothing bound for [{$abstract}].");
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->instances);
    }
}
