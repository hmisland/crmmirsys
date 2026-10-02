<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class DepartmentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'departments';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM departments WHERE id = :id', ['id' => $id]);
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM departments WHERE 1 = 1 ORDER BY name');
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateDepartment(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteDepartment(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
