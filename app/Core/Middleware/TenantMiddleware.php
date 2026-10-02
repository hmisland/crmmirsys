<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * PHASE 3 STATUS: deliberate no-op, kept only for symmetry with the
 * other pipeline-position middleware classes.
 *
 * OrganizationContext is now resolved globally for every request by
 * App\Core\Application::resolveOrganizationContext() — the same fix
 * applied to CurrentUser after the Phase 2 "homepage doesn't know
 * I'm logged in" bug (see Application.php's handle() docblock). A
 * per-route middleware that individual routes could forget to attach
 * is exactly the failure mode that bug came from; this class stays
 * as an explicit marker of where tenant resolution conceptually sits
 * in the Phase 0 §6 chain, not as something still doing the work.
 */
final class TenantMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
