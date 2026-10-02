<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;

/**
 * PHASE 4 STATUS: fully functional — implements the complete Phase 0
 * §6 chain now: installed -> globally enabled -> organization
 * entitled -> user override, via ModuleAccessResolver (shared with
 * ModuleService so the logic exists in exactly one place). RBAC
 * (role -> permission -> scope) is a separate concern, checked
 * afterward by RbacMiddleware, not here.
 *
 * Always 404s on denial, never 403 — per Phase 0 §6, hiding a menu
 * item is not security, and neither is revealing via a 403 that a
 * module *would* work if only it were enabled/entitled.
 */
final class ModuleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $moduleKey)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $resolver = new ModuleAccessResolver(
            new ModuleRepository(),
            new OrganizationModuleRepository(),
            new UserModuleOverrideRepository(),
        );

        if (!$resolver->resolvedForUser($this->moduleKey, CurrentUser::user())) {
            throw new NotFoundException();
        }

        return $next($request);
    }
}
