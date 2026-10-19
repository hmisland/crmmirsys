<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;

/**
 * "Does organization X hold module Y?" for code that runs WITHOUT a
 * logged-in user: cron jobs, webhooks and public links. Same rule as
 * ModuleAccessResolver minus the per-user override (there is no user):
 * installed + globally active + core-or-entitled. Request-time access
 * for a user still goes through ModuleMiddleware / ModuleGate.
 * (Marketing has its own copy of this, Modules\Marketing\Services\Entitlements.)
 */
final class OrganizationEntitlements
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly ModuleRepository $modules = new ModuleRepository(),
        private readonly OrganizationModuleRepository $organizationModules = new OrganizationModuleRepository(),
    ) {
    }

    public function organizationHas(int $organizationId, string $moduleKey): bool
    {
        return $this->memo[$organizationId . '#' . $moduleKey] ??= $this->resolve($organizationId, $moduleKey);
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    private function resolve(int $organizationId, string $moduleKey): bool
    {
        $module = $this->modules->findByKey($moduleKey);
        if ($module === null || ($module['status'] ?? '') !== 'active') {
            return false;
        }
        if (!empty($module['is_core'])) {
            return true;
        }

        return $this->organizationModules->isEnabledForOrganization($organizationId, (int) $module['id']);
    }
}
