<?php

declare(strict_types=1);

namespace App\Infrastructure\Records;

use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\ModuleGate;

/**
 * Which record types exist, and which the current user may use.
 * Core registers contact/company/lead at boot (Application); a module
 * registers its own types in its register() method:
 *
 *     $container->make(RecordTypeRegistry::class)->register(new RecordType(
 *         'contract', 'contracts', 'contracts.view', 'contracts.record', '/contracts/{id}'
 *     ));
 *
 * Being registered is not being usable: isAvailable() also requires the
 * owning module to be available for the user, and canView() adds the
 * view permission — both through ModuleGate, i.e. the existing
 * ModuleAccessResolver + AuthorizationEngine. Record-level checks
 * (own/team scope on a specific row) stay the owning service's job.
 */
final class RecordTypeRegistry
{
    /** @var array<string, RecordType> */
    private array $types = [];

    public function __construct(
        private readonly ModuleGate $gate,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** First registration of a key wins; a duplicate is ignored (and logged) and returns false. */
    public function register(RecordType $type): bool
    {
        if (isset($this->types[$type->key])) {
            $this->logger?->warning('Record type already registered', ['type' => $type->key, 'module' => $type->module]);

            return false;
        }

        $this->types[$type->key] = $type;

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): ?RecordType
    {
        return $this->types[$key] ?? null;
    }

    /** @return RecordType[] every registered type, in registration order */
    public function all(): array
    {
        return array_values($this->types);
    }

    /** Registered and its owning module is available to this user (no permission check). */
    public function isAvailable(string $key, ?array $user): bool
    {
        $type = $this->get($key);

        return $type !== null && $this->gate->allows($user, $type->module, null);
    }

    /** Available, and the user holds the type's view permission. */
    public function canView(string $key, ?array $user): bool
    {
        $type = $this->get($key);

        return $type !== null && $this->gate->allows($user, $type->module, $type->viewPermission);
    }

    /**
     * One record of a type owned by another module, without importing
     * that module — e.g. Contracts reading a deal. Returns null unless:
     * the type has a finder, the user canView() the type, the row exists
     * in the user's organization (the finder is tenant-scoped; checked
     * again here), and the user may view *this* row (record-level
     * own/team/department scope via AuthorizationEngine).
     */
    public function find(string $key, int $id, ?array $user): ?array
    {
        $type = $this->get($key);

        if ($type === null || $type->finder === null || $id <= 0 || !$this->canView($key, $user)) {
            return null;
        }

        $row = ($type->finder)($id);

        if (!is_array($row)) {
            return null;
        }

        if (empty($user['is_super_admin']) && isset($row['organization_id']) && (int) $row['organization_id'] !== (int) ($user['organization_id'] ?? 0)) {
            return null;
        }

        return $this->gate->allows($user, $type->module, $type->viewPermission, $row) ? $row : null;
    }

    /**
     * Like find(), but a row is visible by the owning module's own rule when
     * it registered one (RecordType::$viewer — e.g. Projects: permission scope
     * OR membership, as on the project page) instead of the generic record
     * scope. Opt-in: find() keeps its behavior for every existing caller.
     */
    public function findVisible(string $key, int $id, ?array $user): ?array
    {
        $type = $this->get($key);

        if ($type === null || $type->viewer === null) {
            return $this->find($key, $id, $user);
        }

        if ($type->finder === null || $id <= 0 || $user === null || !$this->canView($key, $user)) {
            return null;
        }

        $row = ($type->finder)($id);

        if (!is_array($row)) {
            return null;
        }

        if (empty($user['is_super_admin']) && isset($row['organization_id']) && (int) $row['organization_id'] !== (int) ($user['organization_id'] ?? 0)) {
            return null;
        }

        return ($type->viewer)($user, $row) ? $row : null;
    }

    /**
     * Does the record exist in the user's organization — independent of
     * whether the user may see it? null when the type is not available to
     * the user (or has no finder): then nothing about its rows is answered.
     * Lets a caller show "deleted" instead of "restricted" without ever
     * reading a row the user may not see.
     */
    public function exists(string $key, int $id, ?array $user): ?bool
    {
        $type = $this->get($key);

        if ($type === null || $type->finder === null || !$this->canView($key, $user)) {
            return null;
        }

        $row = $id > 0 ? ($type->finder)($id) : null;

        return is_array($row)
            && (!empty($user['is_super_admin']) || !isset($row['organization_id']) || (int) $row['organization_id'] === (int) ($user['organization_id'] ?? 0));
    }

    /**
     * Rows of a type this user may pick (the owner's lister), [] when the
     * type is not available to the user or has no lister.
     */
    public function listFor(string $key, ?array $user, int $limit = 200): array
    {
        $type = $this->get($key);

        if ($type === null || $type->lister === null || $user === null || !$this->canView($key, $user)) {
            return [];
        }

        return ($type->lister)($user, max(1, $limit));
    }

    /** @return RecordType[] the types this user may view */
    public function viewableBy(?array $user): array
    {
        return array_values(array_filter($this->types, fn (RecordType $t) => $this->canView($t->key, $user)));
    }
}
