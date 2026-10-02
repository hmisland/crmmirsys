<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Extends TenantScopedRepository so an org admin's queries are
 * automatically confined to their own organization_id — this table
 * legitimately has that column (unlike Roles/Modules), so the normal
 * automatic scoping is exactly right here.
 */
final class OrganizationModuleRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'organization_modules';
    }

    public function find(int $moduleId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM organization_modules WHERE module_id = :module_id', ['module_id' => $moduleId]);
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM organization_modules WHERE 1 = 1');
    }

    public function setEnabled(int $moduleId, bool $enabled): void
    {
        $existing = $this->find($moduleId);
        $now = date('Y-m-d H:i:s');

        if ($existing === null) {
            $this->tenantInsertGetId([
                'module_id' => $moduleId,
                'enabled' => $enabled ? 1 : 0,
                'enabled_at' => $enabled ? $now : null,
                'disabled_at' => $enabled ? null : $now,
            ]);

            return;
        }

        $this->tenantUpdate(
            [
                'enabled' => $enabled ? 1 : 0,
                'enabled_at' => $enabled ? $now : $existing['enabled_at'],
                'disabled_at' => $enabled ? null : $now,
            ],
            'module_id = :module_id',
            ['module_id' => $moduleId]
        );
    }

    /**
     * Cross-organization existence check used only by dependency
     * validation (e.g. "is module X enabled for organization Y" while
     * resolving a different org's request) — takes organization_id
     * explicitly rather than relying on OrganizationContext, since
     * the caller may be checking about an org other than the current
     * one is scoped to (in practice: always the same org today, but
     * this keeps the method honestly named for what it does).
     */
    public function isEnabledForOrganization(int $organizationId, int $moduleId): bool
    {
        $row = $this->findOne(
            'SELECT enabled FROM organization_modules WHERE organization_id = :org_id AND module_id = :module_id',
            ['org_id' => $organizationId, 'module_id' => $moduleId]
        );

        return $row !== null && (bool) $row['enabled'];
    }
}
