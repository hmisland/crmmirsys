<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\AuthenticationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;

/**
 * PHASE 2 STATUS: fully functional.
 *
 * By the time any middleware runs, App\Core\Application::handle() has
 * already resolved CurrentUser for the request (for EVERY request,
 * not just protected ones — see its resolveCurrentUser() docblock).
 * This middleware's only job is to enforce "a route marked as
 * protected requires someone to actually be logged in" — it does not
 * re-fetch the user itself, so there is exactly one place a session
 * id gets turned into a user row.
 *
 * A guest hitting a protected web route is redirected to /login; a
 * guest hitting a protected JSON/API route gets a 401 — the same
 * web-vs-API branching pattern ErrorHandler uses.
 *
 * This is the first link only: "is anyone logged in at all". It does
 * NOT check organization membership (TenantMiddleware, Phase 3) or
 * permissions (RbacMiddleware, Phase 3) — those remain placeholders
 * until Organizations/RBAC exist, per the Phase 0 §6 resolution order.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (CurrentUser::isGuest()) {
            if ($request->isJson()) {
                throw new AuthenticationException();
            }

            return Response::redirect('/login');
        }

        $request->setAttribute('auth_user', CurrentUser::user());

        return $next($request);
    }
}
