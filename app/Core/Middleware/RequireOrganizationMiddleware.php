<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * For endpoints whose data always belongs to one organization (contacts,
 * ...). A Super Admin has no organization, so without this every
 * tenant-scoped query would throw "No OrganizationContext" and surface as a
 * 500. They get a 404 instead — the same answer a user without access gets.
 * (Super Admin cross-organization access stays an explicit
 * SuperAdminContext::run(), never an implicit API bypass.)
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
