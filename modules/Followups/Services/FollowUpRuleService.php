<?php

declare(strict_types=1);

namespace Modules\Followups\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Followups\FollowUpRuleRepository;

use function App\Infrastructure\Theme\t;

final class FollowUpRuleService
{
    public function __construct(
        private readonly FollowUpRuleRepository $rules,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::FOLLOWUPS_MANAGE_RULES);

        return $this->rules->all();
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::FOLLOWUPS_MANAGE_RULES);

        $fields = $this->validatedFields($input);
        $fields['created_by_user_id'] = $actingUser['id'];

        $id = $this->rules->create($fields);

        return $this->rules->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->authorize($actingUser, Permission::FOLLOWUPS_MANAGE_RULES);
        $this->requireRule($id);

        $this->rules->updateRule($id, $this->validatedFields($input));

        return $this->rules->findById($id);
    }

    public function toggle(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FOLLOWUPS_MANAGE_RULES);
        $rule = $this->requireRule($id);

        $this->rules->updateRule($id, ['enabled' => $rule['enabled'] ? 0 : 1]);

        return $this->rules->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::FOLLOWUPS_MANAGE_RULES);
        $this->requireRule($id);

        $this->rules->deleteRule($id);
    }

    private function validatedFields(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException(['name' => [t('followups.error.rule_name_required')]]);
        }

        $eventType = trim((string) ($input['event_type'] ?? ''));
        if ($eventType === '') {
            throw new ValidationException(['event_type' => [t('followups.error.rule_event_required')]]);
        }

        $actionConfig = trim((string) ($input['action_configuration_json'] ?? ''));
        if ($actionConfig === '') {
            throw new ValidationException(['action_configuration_json' => [t('followups.error.rule_action_required')]]);
        }
        if (json_decode($actionConfig, true) === null && $actionConfig !== 'null') {
            throw new ValidationException(['action_configuration_json' => [t('followups.error.rule_action_json')]]);
        }

        $conditions = trim((string) ($input['conditions_json'] ?? ''));
        if ($conditions !== '' && json_decode($conditions, true) === null) {
            throw new ValidationException(['conditions_json' => [t('followups.error.rule_conditions_json')]]);
        }

        return [
            'name' => $name,
            'description' => isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null,
            'event_type' => $eventType,
            'conditions_json' => $conditions !== '' ? $conditions : null,
            'action_type' => isset($input['action_type']) && trim((string) $input['action_type']) !== '' ? trim((string) $input['action_type']) : 'create_follow_up',
            'action_configuration_json' => $actionConfig,
            'priority' => isset($input['priority']) && $input['priority'] !== '' ? (int) $input['priority'] : 0,
            'enabled' => isset($input['enabled']) ? 1 : 0,
        ];
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function requireRule(int $id): array
    {
        $rule = $this->rules->findById($id);

        if ($rule === null) {
            throw new NotFoundException('Rule not found.');
        }

        return $rule;
    }
}
