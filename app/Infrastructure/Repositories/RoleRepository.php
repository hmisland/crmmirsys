<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Deliberately extends BaseRepository, not TenantScopedRepository:
 * TenantScopedRepository's automatic `organization_id = :org_id`
 * filter would exclude system role templates entirely (their
 * organization_id is NULL, per the roles migration's docblock).
 * Callers (RoleService) are responsible for only exposing an org's
 * own roles plus system templates — never another organization's
 * custom roles — which findAvailableForOrganization() does by query,
 * not by a blanket automatic filter.
 */
final class RoleRepository extends BaseRepository
{
    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);
    }

    /**
     * Every role a given organization may assign: its own custom
     * roles plus every system template.
     */
    public function findAvailableForOrganization(int $organizationId): array
    {
        return $this->findMany(
            'SELECT * FROM roles WHERE organization_id = :org_id OR organization_id IS NULL
             ORDER BY is_system DESC, name',
            ['org_id' => $organizationId]
        );
    }

    public function findSystemTemplates(): array
    {
        return $this->findMany('SELECT * FROM roles WHERE organization_id IS NULL AND is_system = 1 ORDER BY name');
    }

    public function create(array $data): int
    {
        return $this->insertGetId('roles', $data);
    }

    public function updateRole(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->update('roles', $fields, 'id = :id', ['id' => $id]);
    }

    public function deleteRole(int $id): void
    {
        $this->delete('roles', 'id = :id', ['id' => $id]);
    }
}
