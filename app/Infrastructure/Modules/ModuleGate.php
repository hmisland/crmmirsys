<?php

declare(strict_types=1);

namespace App\Infrastructure\Modules;

use App\Infrastructure\Authorization\AuthorizationEngine;

/**
 * One question the record-type and related-panel registries ask:
 * "may this user use something owned by module X that needs
 * permission Y?" It adds no access rules of its own — the module part
 * is ModuleAccessResolver (installed -> globally active -> organization
 * entitled -> user override, the same chain ModuleMiddleware enforces)
 * and the permission part is AuthorizationEngine::can().
 *
 * A null module means Core (always on); a null permission means none
 * required beyond being logged in. A guest is never allowed.
 *
 * Built from two closures so the registries can be tested without a
 * database; Application uses fromCore().
 */
final class ModuleGate
{
    /** @var array<string, bool> per-request memo: "module#userId" => allowed */
    private array $moduleMemo = [];

    /**
     * @param \Closure(string, array): bool $moduleAllowed
     * @param \Closure(array, string, ?array): bool $permissionAllowed third argument: optional record for own/team/department scope
     */
    public function __construct(
        private readonly \Closure $moduleAllowed,
        private readonly \Closure $permissionAllowed,
    ) {
    }

    public static function fromCore(ModuleAccessResolver $resolver, AuthorizationEngine $authorization): self
    {
        return new self(
            fn (string $moduleKey, array $user): bool => $resolver->resolvedForUser($moduleKey, $user),
            fn (array $user, string $permission, ?array $record = null): bool => $authorization->can($user, $permission, $record),
        );
    }

    /** $record (optional): also apply the permission's own/team/department scope to that row. */
    public function allows(?array $user, ?string $moduleKey, ?string $permission, ?array $record = null): bool
    {
        if ($user === null) {
            return false;
        }

        if ($moduleKey !== null && !$this->moduleAllowed($moduleKey, $user)) {
            return false;
        }

        return $permission === null || (bool) ($this->permissionAllowed)($user, $permission, $record);
    }

    /** Clears the memo — called at the start of every request (see Application::handle()). */
    public function reset(): void
    {
        $this->moduleMemo = [];
    }

    private function moduleAllowed(string $moduleKey, array $user): bool
    {
        $memoKey = $moduleKey . '#' . ($user['id'] ?? '');

        return $this->moduleMemo[$memoKey] ??= (bool) ($this->moduleAllowed)($moduleKey, $user);
    }
}
