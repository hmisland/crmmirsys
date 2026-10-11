<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;

/**
 * Whether to offer "New follow-up" shortcuts on Core pages (contacts,
 * leads): the Followups module is entitled for the user and they hold
 * followups.create — the same checks the /followups/create route
 * enforces, so a shown button never leads to a 403/404.
 */
final class FollowUpAccess
{
    public static function canCreate(?AuthorizationEngine $authorization, ?array $user): bool
    {
        if ($authorization === null || $user === null) {
            return false;
        }

        $resolver = new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository());

        return $resolver->resolvedForUser('followups', $user)
            && $authorization->can($user, Permission::FOLLOWUPS_CREATE);
    }
}
