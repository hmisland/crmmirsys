<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Extends BaseRepository, not TenantScopedRepository: an organization
 * row's own `id` IS the tenant boundary, there's no organization_id
 * column on organizations to scope by. "Can this caller see/edit this
 * org" is enforced by OrganizationService (their own org, or Super
 * Admin), not by this repository.
 */
final class OrganizationRepository extends BaseRepository
{
    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM organizations WHERE id = :id', ['id' => $id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->findOne('SELECT * FROM organizations WHERE slug = :slug', ['slug' => $slug]);
    }

    public function create(array $data): int
    {
        return $this->insertGetId('organizations', $data);
    }

    public function updateOrganization(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->update('organizations', $fields, 'id = :id', ['id' => $id]);
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM organizations ORDER BY name');
    }
}
