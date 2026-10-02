<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * Wraps a route's final handler with its middleware stack, innermost
 * (the handler) first. This is where the Phase 0 §12–13/§85–91 chain
 * (Auth → Tenant → Module → RBAC) is enforced, in that fixed order —
 * the middleware list a route declares IS that chain.
 */
final class MiddlewarePipeline
{
    /**
     * @param MiddlewareInterface[] $middleware
     * @param callable(Request): Response $handler
     */
    public static function run(array $middleware, callable $handler, Request $request): Response
    {
        $next = $handler;

        foreach (array_reverse($middleware) as $mw) {
            $next = fn (Request $req) => $mw->handle($req, $next);
        }

        return $next($request);
    }
}
