<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class PipelineStageRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'pipeline_stages';
    }

    public function forPipeline(int $pipelineId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM pipeline_stages WHERE pipeline_id = :pipeline_id ORDER BY position, id',
            ['pipeline_id' => $pipelineId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM pipeline_stages WHERE id = :id', ['id' => $id]);
    }

    public function create(int $pipelineId, string $name, int $position, int $probability, bool $isWon = false, bool $isLost = false): int
    {
        return $this->tenantInsertGetId([
            'pipeline_id' => $pipelineId,
            'name' => $name,
            'position' => $position,
            'probability' => $probability,
            'is_won' => $isWon ? 1 : 0,
            'is_lost' => $isLost ? 1 : 0,
        ]);
    }
}
