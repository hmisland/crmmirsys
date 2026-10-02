<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class TeamRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'teams';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM teams WHERE id = :id', ['id' => $id]);
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM teams WHERE 1 = 1 ORDER BY name');
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateTeam(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteTeam(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
