<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Modules\ModuleRegistry;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Repositories\UserRepository;

/**
 * Per Phase 0 §6: implements the full resolution chain —
 * installed -> globally enabled -> organization entitled -> user
 * override — via ModuleAccessResolver (shared with ModuleMiddleware
 * so the logic exists in exactly one place). Per §8, dependency
 * validation happens here too: a module can't be enabled unless its
 * dependencies are already enabled at the same level, and can't be
 * disabled while something else enabled at that level still depends
 * on it.
 *
 * CORRECTION (post-Phase 6): organization-level entitlement
 * (organization_modules.enabled) is now Super-Admin-only, per the
 * commercial model Phase 0 §70/§37–38 actually describes — a module
 * is something an organization is SOLD, not something its own admin
 * can just switch on. Org Admin's role is one level down: restricting
 * which of THEIR OWN users can use a module the org has already been
 * entitled to (user_module_overrides — the data model has existed
 * since Phase 4, only the UI/write-path was missing until now).
 * setOrganizationEnabled() briefly let Org Admin control entitlement
 * directly; that was a mistake, corrected here rather than left in
 * place with a comment explaining why it's wrong.
 */
final class ModuleService extends BaseService
{
    private readonly ModuleAccessResolver $resolver;

    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly ModuleRepository $modules,
        private readonly OrganizationModuleRepository $orgModules,
        private readonly UserModuleOverrideRepository $userOverrides,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
    ) {
        parent::__construct($logger, $events);
        $this->resolver = new ModuleAccessResolver($modules, $orgModules, $userOverrides);
    }

    /**
     * Upserts every module.json discovered on disk (ModuleRegistry,
     * Phase 1) into the `modules` table. Safe to call repeatedly —
     * it's how a newly-deployed module (dropped into /modules/) gets
     * picked up: a Super Admin visits the module manager page, which
     * calls this first, then sees the new module listed.
     */
    public function syncFromRegistry(): void
    {
        foreach (ModuleRegistry::instance()->all() as $manifest) {
            $existing = $this->modules->findByKey($manifest->key);

            $fields = [
                'key' => $manifest->key,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'type' => $manifest->type,
                'is_core' => $manifest->isCore ? 1 : 0,
                'dependencies' => json_encode($manifest->dependencies),
            ];

            if ($existing === null) {
                $fields['status'] = 'active';
                $this->modules->create($fields);
            } else {
                // Deliberately never touches `status` on sync — an
                // admin's disable decision must survive a re-sync
                // (e.g. after a version bump), not be silently
                // reverted to active just because the module.json
                // was re-read.
                $this->modules->updateModule((int) $existing['id'], $fields);
            }
        }
    }

    public function listAll(): array
    {
        return $this->modules->all();
    }

    public function findModule(int $moduleId): array
    {
        return $this->requireModule($moduleId);
    }

    /**
     * @return array<int, array> modules the organization can see,
     *   each with an added 'enabled_for_org' bool
     */
    public function listForOrganization(int $organizationId): array
    {
        $result = [];

        foreach ($this->modules->all() as $module) {
            $module['enabled_for_org'] = (bool) $module['is_core']
                || $this->orgModules->isEnabledForOrganization($organizationId, (int) $module['id']);
            $result[] = $module;
        }

        return $result;
    }

    // --- Global, platform-wide (Super Admin) -----------------------------

    public function setGlobalStatus(array $actingUser, int $moduleId, bool $active): void
    {
        $this->requireSuperAdmin($actingUser);

        $module = $this->requireModule($moduleId);

        if ((bool) $module['is_core']) {
            throw new ValidationException(['module' => ['Core modules cannot be disabled.']]);
        }

        if (!$active) {
            $dependents = $this->findActiveDependents($module['key']);

            if ($dependents !== []) {
                $names = implode(', ', array_column($dependents, 'name'));
                throw new ValidationException([
                    'module' => ["Cannot disable — still required by: {$names}. Disable those first."],
                ]);
            }
        }

        $this->modules->updateModule($moduleId, ['status' => $active ? 'active' : 'disabled']);
    }

    /** @return array[] active modules whose dependencies include $key */
    private function findActiveDependents(string $key): array
    {
        $dependents = [];

        foreach ($this->modules->all() as $module) {
            if ($module['status'] !== 'active') {
                continue;
            }

            $dependencies = json_decode((string) $module['dependencies'], true) ?: [];

            if (in_array($key, $dependencies, true)) {
                $dependents[] = $module;
            }
        }

        return $dependents;
    }

    // --- Per-organization entitlement (Super Admin ONLY) ------------------

    /**
     * Grants or revokes one organization's access to a module — the
     * commercial "this org paid for Sales" decision. $organizationId
     * is an explicit target, not the acting Super Admin's own (they
     * have none) — OrganizationContext is temporarily pinned to it so
     * OrganizationModuleRepository's normal tenant-scoped methods work
     * correctly against that specific org, then restored.
     */
    public function setOrganizationEntitlement(array $actingUser, int $organizationId, int $moduleId, bool $enabled): void
    {
        $this->requireSuperAdmin($actingUser);

        $module = $this->requireModule($moduleId);

        if ((bool) $module['is_core']) {
            throw new ValidationException(['module' => ['Core modules are always enabled and cannot be toggled.']]);
        }

        if ($module['status'] !== 'active') {
            throw new ValidationException(['module' => ['This module is currently disabled platform-wide.']]);
        }

        OrganizationContext::runAs($organizationId, function () use ($organizationId, $moduleId, $enabled, $module) {
            if ($enabled) {
                $this->assertDependenciesEnabledForOrganization($organizationId, $module);
            } else {
                $dependents = $this->findEnabledDependentsForOrganization($organizationId, $module['key']);

                if ($dependents !== []) {
                    $names = implode(', ', array_column($dependents, 'name'));
                    throw new ValidationException([
                        'module' => ["Cannot disable — still required by: {$names}. Disable those first."],
                    ]);
                }
            }

            $this->orgModules->setEnabled($moduleId, $enabled);
        });
    }

    private function assertDependenciesEnabledForOrganization(int $organizationId, array $module): void
    {
        $dependencies = json_decode((string) $module['dependencies'], true) ?: [];

        foreach ($dependencies as $depKey) {
            $dependency = $this->modules->findByKey($depKey);

            if ($dependency === null) {
                continue;
            }

            $depEnabled = (bool) $dependency['is_core']
                || $this->orgModules->isEnabledForOrganization($organizationId, (int) $dependency['id']);

            if (!$depEnabled) {
                throw new ValidationException([
                    'module' => ["Enable \"{$dependency['name']}\" first — this module depends on it."],
                ]);
            }
        }
    }

    private function findEnabledDependentsForOrganization(int $organizationId, string $key): array
    {
        $dependents = [];

        foreach ($this->modules->all() as $module) {
            if ($module['status'] !== 'active') {
                continue;
            }

            $enabled = (bool) $module['is_core']
                || $this->orgModules->isEnabledForOrganization($organizationId, (int) $module['id']);

            if (!$enabled) {
                continue;
            }

            $dependencies = json_decode((string) $module['dependencies'], true) ?: [];

            if (in_array($key, $dependencies, true)) {
                $dependents[] = $module;
            }
        }

        return $dependents;
    }

    // --- Per-user restriction within an already-entitled module (Org Admin) ---

    /**
     * @return array{users: array, overrides: array<int, string>} every
     *   user in the org, plus a user_id => 'enabled'|'disabled' map for
     *   whoever currently has an explicit override on this module
     */
    public function userAccessForModule(array $actingUser, int $moduleId): array
    {
        $this->authorize($actingUser, Permission::MODULES_MANAGE);

        $organizationId = (int) $actingUser['organization_id'];
        $module = $this->requireModule($moduleId);

        if (!((bool) $module['is_core']) && !$this->orgModules->isEnabledForOrganization($organizationId, $moduleId)) {
            throw new ValidationException(['module' => ['Your organization does not have this module enabled — ask your Super Admin.']]);
        }

        return [
            'users' => $this->users->findAllInOrganization($organizationId),
            'overrides' => $this->userOverrides->forModuleInOrganization($organizationId, $moduleId),
        ];
    }

    /**
     * Org Admin restricting (or un-restricting) one of their own users
     * from a module the ORGANIZATION already has. Cannot grant a user
     * access to a module the org itself doesn't have — there is
     * nothing to override in that case, per the Phase 0 §6 chain
     * (organization entitlement is checked before user override).
     */
    public function setUserAccess(array $actingUser, int $targetUserId, int $moduleId, bool $restricted): void
    {
        $this->authorize($actingUser, Permission::MODULES_MANAGE);

        $organizationId = (int) $actingUser['organization_id'];
        $module = $this->requireModule($moduleId);

        $target = $this->users->findById($targetUserId);
        if ($target === null || (int) ($target['organization_id'] ?? 0) !== $organizationId) {
            throw new NotFoundException('User not found.');
        }

        if (!((bool) $module['is_core']) && !$this->orgModules->isEnabledForOrganization($organizationId, $moduleId)) {
            throw new ValidationException(['module' => ['Your organization does not have this module enabled.']]);
        }

        // restricted=true -> explicit override enabled=false.
        // restricted=false -> remove the override entirely (inherit
        // the organization's own setting, which is "enabled" — that's
        // the only reason this user page is reachable at all).
        $this->userOverrides->setOverride($organizationId, $targetUserId, $moduleId, $restricted ? false : null);
    }

    // --- Resolution (used by ModuleMiddleware) ---------------------------

    /**
     * The full Phase 0 §6 chain for one module, for the currently
     * resolved user. Returns false the moment any link fails —
     * ModuleMiddleware turns a false here into a 404, never revealing
     * which link failed. See ModuleAccessResolver for the actual logic.
     */
    public function resolvedForUser(string $moduleKey, ?array $user): bool
    {
        return $this->resolver->resolvedForUser($moduleKey, $user);
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requireSuperAdmin(array $actingUser): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }

    private function requireModule(int $moduleId): array
    {
        $module = $this->modules->findById($moduleId);

        if ($module === null) {
            throw new NotFoundException('Module not found.');
        }

        return $module;
    }
}
