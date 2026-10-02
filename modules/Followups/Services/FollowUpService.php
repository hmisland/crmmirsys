<?php

declare(strict_types=1);

namespace Modules\Followups\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Followups\FollowUpRepository;

final class FollowUpService
{
    private const TYPES = ['call', 'email', 'message', 'meeting', 'task', 'reminder', 'note', 'custom'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const OPEN_STATUSES = ['pending', 'scheduled', 'in_progress'];

    public function __construct(
        private readonly FollowUpRepository $followUps,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::FOLLOWUPS_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        if ($scope === Scope::OWN) {
            $filters['owner_id'] = $actingUser['id'];
        }

        return $this->followUps->search($filters);
    }

    /** For the dashboard tile. Returns an empty list rather than throwing when the user has no followups.view at all — a dashboard widget should degrade quietly, not error the whole page. */
    public function topForDashboard(array $actingUser, int $limit = 5): array
    {
        if ($this->authorization->scopeFor($actingUser, Permission::FOLLOWUPS_VIEW) === null) {
            return [];
        }

        return $this->followUps->topForUser((int) $actingUser['id'], $limit);
    }

    /** For a related entity's own page (e.g. a Deal's timeline/sidebar) — authorization is the caller's job, since it already checked access to the parent entity. */
    public function forRelated(string $relatedType, int $relatedId): array
    {
        return $this->followUps->search(['related_type' => $relatedType, 'related_id' => $relatedId]);
    }

    public function find(array $actingUser, int $id): array
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_VIEW, $followUp);

        return $followUp;
    }

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::FOLLOWUPS_CREATE)) {
            throw new AuthorizationException();
        }

        $fields = $this->validatedFields($input);
        $fields['organization_id'] = $actingUser['organization_id'];
        $fields['owner_id'] = $actingUser['id'];
        $fields['created_by_user_id'] = $actingUser['id'];
        $fields['is_automated'] = 0;

        $id = $this->followUps->create($fields);
        $this->recordTimeline($id, $fields, $actingUser['id']);

        return $this->followUps->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_EDIT, $followUp);

        $fields = $this->validatedFields($input);
        $this->followUps->updateFollowUp($id, $fields);
        $this->timeline->record((string) ($fields['related_type'] ?? 'follow_up'), $fields['related_id'] ?? $id, 'follow_up_updated', 'Follow-up "' . $fields['title'] . '" updated', (int) $actingUser['id']);

        return $this->followUps->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_DELETE, $followUp);

        $this->followUps->deleteFollowUp($id);
    }

    public function complete(array $actingUser, int $id): array
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_COMPLETE, $followUp);

        $this->followUps->updateFollowUp($id, ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);
        $this->timeline->record((string) ($followUp['related_type'] ?? 'follow_up'), (int) ($followUp['related_id'] ?? $id), 'follow_up_completed', 'Follow-up "' . $followUp['title'] . '" completed', (int) $actingUser['id']);

        return $this->followUps->findById($id);
    }

    public function cancel(array $actingUser, int $id): array
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_CANCEL, $followUp);

        $this->followUps->updateFollowUp($id, ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')]);
        $this->timeline->record((string) ($followUp['related_type'] ?? 'follow_up'), (int) ($followUp['related_id'] ?? $id), 'follow_up_cancelled', 'Follow-up "' . $followUp['title'] . '" cancelled', (int) $actingUser['id']);

        return $this->followUps->findById($id);
    }

    public function reschedule(array $actingUser, int $id, string $dueAt): array
    {
        $followUp = $this->requireFollowUp($id);
        $this->authorizeRecord($actingUser, Permission::FOLLOWUPS_EDIT, $followUp);

        if ($dueAt === '') {
            throw new ValidationException(['due_at' => ['A new due date/time is required.']]);
        }

        $this->followUps->updateFollowUp($id, ['due_at' => $dueAt, 'status' => in_array($followUp['status'], self::OPEN_STATUSES, true) ? $followUp['status'] : 'pending']);
        $this->timeline->record((string) ($followUp['related_type'] ?? 'follow_up'), (int) ($followUp['related_id'] ?? $id), 'follow_up_rescheduled', 'Follow-up "' . $followUp['title'] . '" rescheduled', (int) $actingUser['id']);

        return $this->followUps->findById($id);
    }

    /**
     * Called only by FollowUpRuleEngineListener — no acting-user
     * permission check, since there is no human actor; the rule's
     * own existence (created by someone with followups.manage_rules)
     * is the authorization. organization_id comes from the event,
     * never guessed from ambient context, since rule processing must
     * stay correctly scoped to the entity's own organization.
     */
    public function createAutomated(array $fields): int
    {
        $fields['is_automated'] = 1;
        $fields['created_by_user_id'] = $fields['owner_id'];

        $id = $this->followUps->create($fields);
        $this->recordTimeline($id, $fields, null);

        return $id;
    }

    private function recordTimeline(int $id, array $fields, ?int $actorUserId): void
    {
        if (!empty($fields['related_type']) && !empty($fields['related_id'])) {
            $this->timeline->record((string) $fields['related_type'], (int) $fields['related_id'], 'follow_up_created', 'Follow-up "' . $fields['title'] . '" created', $actorUserId);
        }
    }

    private function validatedFields(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['title' => ['Title is required.']]);
        }

        $type = in_array($input['type'] ?? 'task', self::TYPES, true) ? $input['type'] : 'task';
        $priority = in_array($input['priority'] ?? 'normal', self::PRIORITIES, true) ? $input['priority'] : 'normal';

        return [
            'title' => $title,
            'description' => isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null,
            'type' => $type,
            'priority' => $priority,
            'assignee_user_id' => isset($input['assignee_user_id']) && $input['assignee_user_id'] !== '' ? (int) $input['assignee_user_id'] : null,
            'team_id' => isset($input['team_id']) && $input['team_id'] !== '' ? (int) $input['team_id'] : null,
            'department_id' => isset($input['department_id']) && $input['department_id'] !== '' ? (int) $input['department_id'] : null,
            'due_at' => isset($input['due_at']) && $input['due_at'] !== '' ? $input['due_at'] : null,
            'timezone' => isset($input['timezone']) && $input['timezone'] !== '' ? $input['timezone'] : 'Asia/Tehran',
            'related_type' => isset($input['related_type']) && $input['related_type'] !== '' ? $input['related_type'] : null,
            'related_id' => isset($input['related_id']) && $input['related_id'] !== '' ? (int) $input['related_id'] : null,
        ];
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireFollowUp(int $id): array
    {
        $followUp = $this->followUps->findById($id);

        if ($followUp === null) {
            throw new NotFoundException('Follow-up not found.');
        }

        return $followUp;
    }
}
