<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Repositories\RolePermissionRepository;
use App\Infrastructure\Repositories\UserPermissionGrantRepository;
use App\Infrastructure\Security\CurrentUser;

/**
 * PHASE 3 STATUS: fully functional.
 *
 * A route-level pre-check: reject before the controller/Service even
 * runs if the caller plainly doesn't have the permission at all. The
 * Services this route eventually calls (UserManagementService,
 * RoleService, OrgStructureService) re-check the SAME permission
 * themselves via the same AuthorizationEngine — this is deliberate
 * defense in depth (Phase 0 §65: "server-side authorization is
 * mandatory," never a single point that could be bypassed), not
 * redundant duplication to trim away. AuthMiddleware must run before
 * this in a route's middleware list — this only checks CurrentUser,
 * it does not establish it.
 */
final class RbacMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $permission)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = CurrentUser::user();

        if ($user === null) {
            throw new AuthorizationException();
        }

        $engine = new AuthorizationEngine(new RolePermissionRepository(), new UserPermissionGrantRepository());

        if (!$engine->can($user, $this->permission)) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
