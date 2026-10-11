<?php

declare(strict_types=1);

namespace Modules\Contracts;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class ContractCategoryRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contract_categories';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM contract_categories WHERE 1 = 1 ORDER BY name');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contract_categories WHERE id = :id', ['id' => $id]);
    }

    public function findByName(string $name): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contract_categories WHERE name = :name', ['name' => $name]);
    }

    public function create(string $name): int
    {
        return $this->tenantInsertGetId(['name' => $name]);
    }

    public function rename(int $id, string $name): void
    {
        $this->tenantUpdate(['name' => $name], 'id = :id', ['id' => $id]);
    }

    public function deleteCategory(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
