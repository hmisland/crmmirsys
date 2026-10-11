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
use App\Infrastructure\Repositories\DepartmentRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TeamRepository;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Followups\FollowUpRepository;

use function App\Infrastructure\Theme\t;

final class FollowUpService
{
    private const TYPES = ['call', 'email', 'message', 'meeting', 'task', 'reminder', 'note', 'custom'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const OPEN_STATUSES = ['pending', 'scheduled', 'in_progress'];

    /**
     * The only record types a follow-up may relate to. The service accepts
     * just the type string; the record itself is validated by the caller
     * (FollowUpController: Core repositories / RecordTypeRegistry::find()).
     */
    public const RELATED_TYPES = ['contact', 'lead', 'deal', 'contract', 'project'];

    public function __construct(
        private readonly FollowUpRepository $followUps,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly UserRepository $users = new UserRepository(),
        private readonly TeamRepository $teams = new TeamRepository(),
        private readonly DepartmentRepository $departments = new DepartmentRepository(),
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

        $fields = $this->validatedFields($input, $followUp);
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

    /**
     * Deletes every id the acting user may delete; the rest are skipped
     * (not theirs or already gone) — each one goes through delete() and
     * its record-level permission check.
     *
     * @param array<int|string, mixed> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $actingUser, array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)), 0, 500) as $id) {
            try {
                $this->delete($actingUser, $id);
                $deleted++;
            } catch (AuthorizationException | NotFoundException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Per-row delete flag for the list page — the same record-level
     * check delete() enforces.
     *
     * @return array<int, bool>
     */
    public function deletableIds(array $actingUser, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $this->authorization->can($actingUser, Permission::FOLLOWUPS_DELETE, $row);
        }

        return $result;
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
            throw new ValidationException(['due_at' => [t('followups.error.due_at_required')]]);
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

    /** @param array|null $current the stored follow-up on update (null on create) */
    private function validatedFields(array $input, ?array $current = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException(['title' => [t('followups.error.title_required')]]);
        }

        $relatedType = isset($input['related_type']) && $input['related_type'] !== '' ? (string) $input['related_type'] : null;
        if ($relatedType !== null && !in_array($relatedType, self::RELATED_TYPES, true)) {
            throw new ValidationException(['related' => [t('followups.related_invalid')]]);
        }

        // A missing, empty or unknown type/priority never reaches the NOT NULL
        // columns: on create it falls back to the defaults (task / normal, the
        // columns' own defaults); on update it keeps the stored value, so an
        // edit never silently resets it.
        $type = $this->allowedOr($input['type'] ?? null, self::TYPES, (string) ($current['type'] ?? 'task'));
        $priority = $this->allowedOr($input['priority'] ?? null, self::PRIORITIES, (string) ($current['priority'] ?? 'normal'));

        [$assigneeId, $teamId, $departmentId] = $this->validatedPeople($input, $current);

        return [
            'title' => $title,
            'description' => isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null,
            'type' => $type,
            'priority' => $priority,
            'assignee_user_id' => $assigneeId,
            'team_id' => $teamId,
            'department_id' => $departmentId,
            'due_at' => isset($input['due_at']) && $input['due_at'] !== '' ? $input['due_at'] : null,
            'timezone' => isset($input['timezone']) && $input['timezone'] !== '' ? $input['timezone'] : 'Asia/Tehran',
            'related_type' => $relatedType,
            'related_id' => isset($input['related_id']) && $input['related_id'] !== '' ? (int) $input['related_id'] : null,
        ];
    }

    /**
     * assignee_user_id / team_id / department_id must belong to the caller's
     * organization (G4): an assignee is an ACTIVE user of the organization —
     * except the one already saved on this follow-up, which may be kept as is;
     * team and department go through tenant-scoped repositories (another
     * organization's id is "not found"). Lists show the assignee's name, so an
     * unchecked id would surface another organization's user. Empty = none.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private function validatedPeople(array $input, ?array $current): array
    {
        $id = fn (string $key): ?int => isset($input[$key]) && $input[$key] !== '' && $input[$key] !== null ? (int) $input[$key] : null;
        [$assigneeId, $teamId, $departmentId] = [$id('assignee_user_id'), $id('team_id'), $id('department_id')];
        $errors = [];

        if ($assigneeId !== null) {
            $kept = $current !== null && $assigneeId === (int) ($current['assignee_user_id'] ?? 0);
            $user = $assigneeId > 0 ? $this->users->findById($assigneeId) : null;
            $valid = $user !== null && (int) $user['organization_id'] === OrganizationContext::id()
                && ($kept || ($user['status'] ?? '') === 'active');
            if (!$valid) {
                $errors['assignee_user_id'][] = t('followups.error.invalid_assignee');
            }
        }
        if ($teamId !== null && ($teamId <= 0 || $this->teams->findById($teamId) === null)) {
            $errors['team_id'][] = t('followups.error.invalid_team');
        }
        if ($departmentId !== null && ($departmentId <= 0 || $this->departments->findById($departmentId) === null)) {
            $errors['department_id'][] = t('followups.error.invalid_department');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$assigneeId, $teamId, $departmentId];
    }

    /** $value when it is one of $allowed, else $fallback. */
    private function allowedOr(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
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
