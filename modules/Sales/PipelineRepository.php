<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class PipelineRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'pipelines';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM pipelines WHERE 1 = 1 ORDER BY id');
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM pipelines WHERE id = :id', ['id' => $id]);
    }

    public function findDefault(): ?array
    {
        return $this->tenantFindOne('SELECT * FROM pipelines WHERE is_default = 1 LIMIT 1');
    }

    public function create(string $name, bool $isDefault = false): int
    {
        return $this->tenantInsertGetId(['name' => $name, 'is_default' => $isDefault ? 1 : 0]);
    }
}
