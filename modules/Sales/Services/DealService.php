<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Application\Services\TimelineService;
use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Validation\Validator;
use Modules\Sales\DealRepository;
use Modules\Sales\Events\DealStageChanged;

final class DealService
{
    public function __construct(
        private readonly DealRepository $deals,
        private readonly PipelineService $pipelines,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
        private readonly EventBus $events,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::DEALS_VIEW);

        return $this->deals->search($filters);
    }

    /** Deals grouped by stage_id, for the kanban board of one pipeline. */
    public function board(array $actingUser, int $pipelineId): array
    {
        $this->authorize($actingUser, Permission::DEALS_VIEW);

        $deals = $this->deals->forPipeline($pipelineId);
        $byStage = [];

        foreach ($deals as $deal) {
            $byStage[$deal['stage_id']][] = $deal;
        }

        return $byStage;
    }

    public function totalsByStage(array $actingUser, int $pipelineId): array
    {
        $this->authorize($actingUser, Permission::DEALS_VIEW);

        $result = [];
        foreach ($this->deals->totalsByStage($pipelineId) as $row) {
            $result[$row['stage_id']] = ['total' => (float) $row['total'], 'count' => (int) $row['count']];
        }

        return $result;
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::DEALS_VIEW);

        return $this->requireDeal($id);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::DEALS_CREATE);

        $validator = Validator::make($input, [
            'name' => 'required|min:1',
            'pipeline_id' => 'required',
            'stage_id' => 'required',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $stage = $this->pipelines->findStage((int) $input['stage_id']);
        if ($stage === null || (int) $stage['pipeline_id'] !== (int) $input['pipeline_id']) {
            throw new ValidationException(['stage_id' => ['Invalid stage for this pipeline.']]);
        }

        $fields = $this->fieldsFromInput($input);
        $fields['owner_id'] = (int) $actingUser['id'];

        $id = $this->deals->create($fields);
        $this->timeline->record('deal', $id, 'created', 'Deal created', (int) $actingUser['id']);

        return $this->deals->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $deal = $this->requireDeal($id);
        $this->authorizeRecord($actingUser, Permission::DEALS_EDIT, $deal);

        $validator = Validator::make($input, ['name' => 'required|min:1']);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->deals->updateDeal($id, $this->fieldsFromInput($input));
        $this->timeline->record('deal', $id, 'updated', 'Deal details updated', (int) $actingUser['id']);

        return $this->deals->findById($id);
    }

    public function changeStage(array $actingUser, int $id, int $stageId): array
    {
        $deal = $this->requireDeal($id);
        $this->authorizeRecord($actingUser, Permission::DEALS_CHANGE_STAGE, $deal);

        $stage = $this->pipelines->findStage($stageId);
        if ($stage === null || (int) $stage['pipeline_id'] !== (int) $deal['pipeline_id']) {
            throw new ValidationException(['stage_id' => ['Invalid stage for this pipeline.']]);
        }

        $fields = ['stage_id' => $stageId];

        if ((bool) $stage['is_won']) {
            $fields['status'] = 'won';
            $fields['closed_at'] = date('Y-m-d H:i:s');
        } elseif ((bool) $stage['is_lost']) {
            $fields['status'] = 'lost';
            $fields['closed_at'] = date('Y-m-d H:i:s');
        }

        $this->deals->updateDeal($id, $fields);
        $this->timeline->record('deal', $id, 'stage_changed', "Moved to \"{$stage['name']}\"", (int) $actingUser['id']);

        $this->events->dispatch(new DealStageChanged(
            organizationId: (int) $deal['organization_id'],
            dealId: $id,
            stageId: $stageId,
            stageName: (string) $stage['name'],
            isWon: (bool) $stage['is_won'],
            isLost: (bool) $stage['is_lost'],
            actorUserId: (int) $actingUser['id'],
        ));

        return $this->deals->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $deal = $this->requireDeal($id);
        $this->authorizeRecord($actingUser, Permission::DEALS_DELETE, $deal);

        $this->deals->deleteDeal($id);
    }

    private function fieldsFromInput(array $input): array
    {
        return array_filter([
            'name' => trim((string) $input['name']),
            'pipeline_id' => isset($input['pipeline_id']) && $input['pipeline_id'] !== '' ? (int) $input['pipeline_id'] : null,
            'stage_id' => isset($input['stage_id']) && $input['stage_id'] !== '' ? (int) $input['stage_id'] : null,
            'value' => isset($input['value']) && $input['value'] !== '' ? (string) (float) $input['value'] : null,
            'currency_code' => $this->orNull($input, 'currency_code'),
            'contact_id' => isset($input['contact_id']) && $input['contact_id'] !== '' ? (int) $input['contact_id'] : null,
            'company_id' => isset($input['company_id']) && $input['company_id'] !== '' ? (int) $input['company_id'] : null,
            'source' => $this->orNull($input, 'source'),
            'expected_close_date' => $this->orNull($input, 'expected_close_date'),
        ], fn ($v) => $v !== null);
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireDeal(int $id): array
    {
        $deal = $this->deals->findById($id);

        if ($deal === null) {
            throw new NotFoundException('Deal not found.');
        }

        return $deal;
    }
}
