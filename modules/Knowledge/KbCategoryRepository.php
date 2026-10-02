<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class KbCategoryRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'kb_categories';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM kb_categories WHERE 1 = 1 ORDER BY name');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM kb_categories WHERE id = :id', ['id' => $id]);
    }

    public function create(string $name): int
    {
        return $this->tenantInsertGetId(['name' => $name]);
    }

    public function deleteCategory(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
