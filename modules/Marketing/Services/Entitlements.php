<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;

/**
 * Organization-level entitlement for work that runs without a user
 * (queue worker, webhooks, unsubscribe links): the module must be
 * installed, globally active and enabled for that organization — the
 * same rows ModuleAccessResolver reads. Requests by a user go through
 * ModuleMiddleware / ModuleGate instead.
 */
final class Entitlements
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly OrganizationModuleRepository $organizationModules,
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
