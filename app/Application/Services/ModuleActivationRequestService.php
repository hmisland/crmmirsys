<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Repositories\AdminAnnouncementRepository;
use App\Infrastructure\Repositories\ModuleActivationRequestRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;

/**
 * Modules & Features catalog + module activation requests.
 *
 * Organization side (organization.edit): the catalog of registered,
 * platform-active, non-core modules with the organization's REAL
 * entitlement (ModuleService::listForOrganization) and the latest request
 * state, and "request activation". A request never grants anything.
 *
 * Super Admin side: approve = the existing per-organization entitlement
 * (ModuleService::setOrganizationEntitlement, with its dependency and
 * platform checks) and the request marked approved in the SAME
 * transaction — if enabling fails, nothing is recorded and the request
 * stays pending; reject = the decision and an optional reason are stored,
 * the module stays off. Requests are never deleted. The requester is told
 * through the existing announcement bell; the organization's timeline
 * keeps the audit trail.
 */
final class ModuleActivationRequestService
{
    public const REASON_MAX = 500;

    public function __construct(
        private readonly ModuleService $modules,
        private readonly ModuleRepository $moduleRepository,
        private readonly ModuleActivationRequestRepository $requests,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly AdminAnnouncementRepository $announcements,
        private readonly UserRepository $users,
        private readonly string $langPath,
    ) {
    }

    // --- Organization side ------------------------------------------------------

    /**
     * @return array<string, list<array{key: string, id: int, name: string, state: string, request: ?array,
     *   dependencies: list<array{key: string, name: string, active: bool}>, dependents: list<string>, child: bool,
     *   open_url: ?string, highlights: string[]}>> category => modules
     */
    public function catalog(array $user): array
    {
        $organizationId = $this->organizationAdmin($user);
        $all = $this->modules->listForOrganization($organizationId);
        $byKey = array_column($all, null, 'key');
        $latest = $this->requests->latestForOrganization($organizationId);

        $visible = array_filter($all, fn (array $m): bool => self::offered($m));
        $entries = [];
        foreach ($visible as $module) {
            $key = (string) $module['key'];
            $dependencyKeys = self::dependencies($module);
            $request = $latest[(int) $module['id']] ?? null;
            $entries[$key] = [
                'key' => $key,
                'id' => (int) $module['id'],
                'name' => (string) $module['name'],
                'state' => self::state((bool) $module['enabled_for_org'], $request),
                'request' => $request,
                'dependencies' => array_values(array_map(fn (string $dep): array => [
                    'key' => $dep,
                    'name' => (string) ($byKey[$dep]['name'] ?? $dep),
                    'active' => (bool) ($byKey[$dep]['enabled_for_org'] ?? false),
                ], $dependencyKeys)),
                'dependents' => array_values(array_map(
                    fn (array $m): string => (string) $m['key'],
                    array_filter($visible, fn (array $m): bool => in_array($key, self::dependencies($m), true)),
                )),
                'child' => false,
                'open_url' => $module['enabled_for_org'] ? (ModuleCatalog::OPEN_URL[$key] ?? null) : null,
                'highlights' => ModuleCatalog::HIGHLIGHTS[$key] ?? [],
            ];
        }

        // Categories in their fixed order, then "other"; inside a category a module that depends on another
        // module of the same category is listed right under it (marked as a child).
        $categories = [];
        foreach (array_merge(array_keys(ModuleCatalog::CATEGORIES), [ModuleCatalog::OTHER]) as $category) {
            $inCategory = array_filter($entries, fn (array $e): bool => ModuleCatalog::categoryOf($e['key']) === $category);
            uasort($inCategory, fn (array $a, array $b): int => [ModuleCatalog::position($a['key']), $a['name']] <=> [ModuleCatalog::position($b['key']), $b['name']]);
            $ordered = [];
            foreach ($inCategory as $key => $entry) {
                $parent = $entry['dependencies'][0]['key'] ?? null;
                if ($parent !== null && isset($inCategory[$parent])) {
                    continue; // placed under its parent below
                }
                $ordered[] = $entry;
                foreach ($inCategory as $childKey => $child) {
                    if (($child['dependencies'][0]['key'] ?? null) === $key) {
                        $ordered[] = ['child' => true] + $child;
                    }
                }
            }
            if ($ordered !== []) {
                $categories[$category] = $ordered;
            }
        }

        return $categories;
    }

    /** "Request activation": validated against the module registry; one pending request per organization + module. */
    public function request(array $user, string $moduleKey): void
    {
        $organizationId = $this->organizationAdmin($user);
        $module = $this->moduleRepository->findByKey($moduleKey);
        if ($module === null || !self::offered($module)) {
            throw new ValidationException(['module' => ['catalog.error.unknown_module']]);
        }
        $enabled = array_column($this->modules->listForOrganization($organizationId), 'enabled_for_org', 'key')[$moduleKey] ?? false;
        if ($enabled) {
            throw new ValidationException(['module' => ['catalog.error.already_active']]);
        }
        $id = $this->requests->createPending($organizationId, (int) $module['id'], $moduleKey, (int) $user['id']);
        if ($id === null) {
            throw new ValidationException(['module' => ['catalog.error.already_pending']]);
        }
        $this->audit($organizationId, 'module_request.created', 'Activation requested: ' . $moduleKey, (int) $user['id']);
    }

    // --- Super Admin side ---------------------------------------------------------------

    public function adminPage(array $user, array $filters, int $limit, int $offset): array
    {
        $this->superAdmin($user);

        return $this->requests->adminPage($filters, $limit, $offset);
    }

    public function adminCount(array $user, array $filters): int
    {
        $this->superAdmin($user);

        return $this->requests->adminCount($filters);
    }

    /** @return array<int, array> module_id => pending request of one organization */
    public function pendingForOrganization(array $user, int $organizationId): array
    {
        $this->superAdmin($user);

        return $this->requests->pendingForOrganization($organizationId);
    }

    /**
     * Approves: enables the module with the existing entitlement mechanism and
     * records the decision together; any failure (dependency missing, module
     * off platform-wide…) leaves the request pending and is reported.
     *
     * @return bool whether the module was already active before (approved anyway)
     */
    public function approve(array $user, int $requestId): bool
    {
        $this->superAdmin($user);

        [$request, $wasActive] = $this->requests->atomically(function () use ($user, $requestId): array {
            $request = $this->pendingRequest($requestId);
            $organizationId = (int) $request['organization_id'];
            $wasActive = (bool) (array_column($this->modules->listForOrganization($organizationId), 'enabled_for_org', 'id')[(int) $request['module_id']] ?? false);
            // The existing Super Admin entitlement — its ValidationException (e.g. a dependency not enabled) aborts everything.
            $this->modules->setOrganizationEntitlement($user, $organizationId, (int) $request['module_id'], true);
            if (!$this->requests->decide($requestId, ModuleActivationRequestRepository::APPROVED, (int) $user['id'], null)) {
                throw new ValidationException(['request' => ['catalog.admin.error.already_processed']]);
            }

            return [$request, $wasActive];
        });

        $this->audit((int) $request['organization_id'], 'module_request.approved', 'Activation approved: ' . $request['module_key'], (int) $user['id']);
        $this->notify($request, $user, 'approved', null);

        return $wasActive;
    }

    public function reject(array $user, int $requestId, string $reason): void
    {
        $this->superAdmin($user);
        $reason = trim($reason);
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new ValidationException(['reason' => ['catalog.admin.error.reason_length']]);
        }

        $request = $this->requests->atomically(function () use ($user, $requestId, $reason): array {
            $request = $this->pendingRequest($requestId);
            if (!$this->requests->decide($requestId, ModuleActivationRequestRepository::REJECTED, (int) $user['id'], $reason !== '' ? $reason : null)) {
                throw new ValidationException(['request' => ['catalog.admin.error.already_processed']]);
            }

            return $request;
        });

        $this->audit((int) $request['organization_id'], 'module_request.rejected', 'Activation rejected: ' . $request['module_key'], (int) $user['id']);
        $this->notify($request, $user, 'rejected', $reason !== '' ? $reason : null);
    }

    public function pendingCount(): int
    {
        return $this->requests->pendingCount();
    }

    // --- Internals --------------------------------------------------------------------

    /**
     * The organization's timeline, written inside that organization's
     * context: a Super Admin request has no organization of its own.
     */
    private function audit(int $organizationId, string $type, string $description, int $userId): void
    {
        OrganizationContext::runAs($organizationId, fn () => $this->timeline->record('organization', $organizationId, $type, $description, $userId));
    }

    private function pendingRequest(int $requestId): array
    {
        $request = $this->requests->findForUpdate($requestId);
        if ($request === null) {
            throw new NotFoundException();
        }
        if ($request['status'] !== ModuleActivationRequestRepository::PENDING) {
            throw new ValidationException(['request' => ['catalog.admin.error.already_processed']]);
        }

        return $request;
    }

    /** The requester learns the decision through the announcement bell (in their own language). */
    private function notify(array $request, array $reviewer, string $decision, ?string $reason): void
    {
        $requester = $request['requested_by_user_id'] !== null ? $this->users->findById((int) $request['requested_by_user_id']) : null;
        if ($requester === null || ($requester['status'] ?? '') !== 'active') {
            return;
        }
        $locale = in_array($requester['language'] ?? null, Translator::SUPPORTED_LOCALES, true) ? (string) $requester['language'] : 'en';
        $t = new Translator($locale, $this->langPath);
        $module = (string) ($this->moduleRepository->findByKey((string) $request['module_key'])['name'] ?? $request['module_key']);
        $body = $t->get('catalog.notice.' . $decision . '_body', ['module' => $module]);
        if ($reason !== null) {
            $body .= "\n" . $t->get('catalog.notice.reason', ['reason' => $reason]);
        }
        try {
            $this->announcements->create(
                mb_substr($t->get('catalog.notice.' . $decision . '_title', ['module' => $module]), 0, 200),
                $body,
                'single',
                (int) $reviewer['id'],
                [['id' => (int) $requester['id'], 'organization_id' => (int) $request['organization_id']]],
            );
        } catch (\PDOException $e) {
            // The decision is already committed; a failed notice must not report it as failed.
            error_log('module request notice not sent: ' . $e->getMessage());
        }
    }

    /** A registered module that may be offered: active platform-wide, not core, not a sample. */
    private static function offered(array $module): bool
    {
        return ($module['status'] ?? '') === 'active' && empty($module['is_core']) && !in_array((string) $module['key'], ModuleCatalog::HIDDEN, true);
    }

    /** @return string[] */
    private static function dependencies(array $module): array
    {
        $deps = json_decode((string) ($module['dependencies'] ?? '[]'), true);

        return is_array($deps) ? array_values(array_filter($deps, 'is_string')) : [];
    }

    private static function state(bool $enabled, ?array $request): string
    {
        return match (true) {
            $enabled => 'active',
            ($request['status'] ?? null) === ModuleActivationRequestRepository::PENDING => 'pending',
            ($request['status'] ?? null) === ModuleActivationRequestRepository::REJECTED => 'rejected',
            default => 'available',
        };
    }

    private function organizationAdmin(array $user): int
    {
        $organizationId = (int) ($user['organization_id'] ?? 0);
        if ($organizationId <= 0 || !$this->authorization->can($user, Permission::ORGANIZATION_EDIT)) {
            throw new AuthorizationException();
        }

        return $organizationId;
    }

    private function superAdmin(array $user): void
    {
        if (empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
