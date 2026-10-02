<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;

/**
 * Per Phase 0 §6: installed -> globally enabled -> organization
 * entitled -> user override. Pulled out of ModuleService (which also
 * handles admin actions like sync/enable/disable and needs a
 * Logger/EventBus for those) so ModuleMiddleware — which runs on
 * every module-gated request and only ever needs this one read-only
 * check — can construct it directly without pulling in dependencies
 * it doesn't use. ModuleService::resolvedForUser() delegates here too,
 * so the logic exists in exactly one place.
 */
final class ModuleAccessResolver
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly OrganizationModuleRepository $orgModules,
        private readonly UserModuleOverrideRepository $userOverrides,
    ) {
    }

    public function resolvedForUser(string $moduleKey, ?array $user): bool
    {
        $module = $this->modules->findByKey($moduleKey);

        if ($module === null || $module['status'] !== 'active') {
            return false; // not installed, or globally disabled
        }

        if ((bool) $module['is_core']) {
            return true; // Core is always on for everyone
        }

        if ($user === null) {
            return false;
        }

        if (!empty($user['is_super_admin'])) {
            return true; // same bypass principle as tenant isolation's SuperAdminContext
        }

        $organizationId = $user['organization_id'] ?? null;

        if ($organizationId === null) {
            return false;
        }

        $orgEnabled = $this->orgModules->isEnabledForOrganization((int) $organizationId, (int) $module['id']);

        $override = $this->userOverrides->find((int) $organizationId, (int) $user['id'], (int) $module['id']);

        if ($override !== null) {
            return (bool) $override['enabled'];
        }

        return $orgEnabled;
    }
}
