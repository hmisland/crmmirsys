<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Core\Exceptions\NotFoundException;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Marketing always belongs to one organization. A Super Admin has no
 * organization (ModuleAccessResolver lets them past the module check),
 * so without this the tenant-scoped repositories would throw "No
 * OrganizationContext" and surface as a 500 (Marketing 3.1, F4).
 * 404 instead — the same answer a non-entitled user gets. Same pattern
 * as the Appointments module's own middleware (not imported: modules
 * never depend on each other).
 */
final class RequireOrganizationMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!OrganizationContext::has()) {
            throw new NotFoundException();
        }

        return $next($request);
    }
}
