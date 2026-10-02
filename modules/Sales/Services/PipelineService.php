<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Validation\Validator;
use Modules\Sales\PipelineRepository;
use Modules\Sales\PipelineStageRepository;

/**
 * Per Phase 0 §22: pipelines/stages are configurable per organization,
 * not fixed. ensureDefaultPipeline() is what gives a newly-enabled
 * organization a working out-of-the-box pipeline (New → Contacted →
 * Qualified → Proposal → Negotiation → Won/Lost, per Phase 0's own
 * example) without an admin having to configure one from scratch —
 * that example is only ever a starting point, still fully editable.
 */
final class PipelineService
{
    public function __construct(
        private readonly PipelineRepository $pipelines,
        private readonly PipelineStageRepository $stages,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function ensureDefaultPipeline(): array
    {
        $existing = $this->pipelines->findDefault();

        if ($existing !== null) {
            return $existing;
        }

        $pipelineId = $this->pipelines->create('Default Pipeline', true);

        $stages = [
            ['New', 10, 0, false, false],
            ['Contacted', 20, 20, false, false],
            ['Qualified', 30, 40, false, false],
            ['Proposal', 40, 60, false, false],
            ['Negotiation', 50, 80, false, false],
            ['Won', 60, 100, true, false],
            ['Lost', 70, 0, false, true],
        ];

        foreach ($stages as [$name, $position, $probability, $isWon, $isLost]) {
            $this->stages->create($pipelineId, $name, $position, $probability, $isWon, $isLost);
        }

        return $this->pipelines->findById($pipelineId);
    }

    public function all(): array
    {
        return $this->pipelines->all();
    }

    public function find(int $id): ?array
    {
        return $this->pipelines->findById($id);
    }

    public function stagesFor(int $pipelineId): array
    {
        return $this->stages->forPipeline($pipelineId);
    }

    public function findStage(int $id): ?array
    {
        return $this->stages->findById($id);
    }

    public function createPipeline(array $actingUser, string $name): array
    {
        $this->authorize($actingUser, Permission::PIPELINES_MANAGE);

        $validator = Validator::make(['name' => $name], ['name' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $id = $this->pipelines->create(trim($name));

        return $this->pipelines->findById($id);
    }

    public function addStage(array $actingUser, int $pipelineId, string $name, int $probability, bool $isWon, bool $isLost): array
    {
        $this->authorize($actingUser, Permission::PIPELINES_MANAGE);

        $validator = Validator::make(['name' => $name], ['name' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $existing = $this->stages->forPipeline($pipelineId);
        $position = (count($existing) + 1) * 10;

        $id = $this->stages->create($pipelineId, trim($name), $position, max(0, min(100, $probability)), $isWon, $isLost);

        return $this->stages->findById($id);
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
