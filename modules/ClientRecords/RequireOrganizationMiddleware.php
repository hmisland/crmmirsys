<?php

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Core\Exceptions\NotFoundException;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Client records always belong to one organization. A Super Admin has
 * no organization (ModuleAccessResolver lets them past the module
 * check), so without this every tenant-scoped query would throw "No
 * OrganizationContext" and surface as a 500. 404 instead — same
 * response a non-entitled user gets.
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
