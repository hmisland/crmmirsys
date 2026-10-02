<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\NotFoundException;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\MiddlewarePipeline;

/**
 * Small, dependency-free router. Supports static and {param} segments,
 * per-route middleware stacks, and route groups (shared prefix +
 * shared middleware) so a module's routes.php can register everything
 * behind, say, ModuleMiddleware('sales') + RbacMiddleware('deals.view')
 * in one place.
 */
final class Router
{
    /**
     * @var array<string, array<int, array{pattern: string, paramNames: string[], handler: callable, middleware: MiddlewareInterface[]}>>
     */
    private array $routes = [];

    private string $groupPrefix = '';

    /** @var MiddlewareInterface[] */
    private array $groupMiddleware = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param MiddlewareInterface[] $middleware
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $fullPath = rtrim($this->groupPrefix . $path, '/');
        $fullPath = $fullPath === '' ? '/' : $fullPath;

        $paramNames = [];
        // {name} captures one segment; {name*} captures the rest of the
        // path including slashes (used for the theme-asset catch-all route).
        $pattern = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return ($m[2] ?? '') === '*' ? '(.*)' : '([^/]+)';
        }, $fullPath);

        $this->routes[$method][] = [
            'pattern' => '#^' . $pattern . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes[$request->method] ?? [] as $route) {
            if (preg_match($route['pattern'], $request->path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['paramNames'], $matches) ?: [];

                foreach ($params as $name => $value) {
                    $request->setAttribute($name, $value);
                }

                return MiddlewarePipeline::run(
                    $route['middleware'],
                    fn (Request $req) => ($route['handler'])($req),
                    $request
                );
            }
        }

        throw new NotFoundException("No route matches {$request->method} {$request->path}");
    }
}
