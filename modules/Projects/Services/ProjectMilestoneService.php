<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Projects\ProjectMilestoneRepository;
use Modules\Projects\ProjectPermission;

/**
 * Milestones of a project (Phase 1): name, date, simple status. No new
 * permission and no state machine — the project's own rules apply:
 *  - reading: whoever may view the project (ProjectService::find():
 *    view scope or membership; another organization's project is 404);
 *  - writing: projects.edit on the project row AND the project in an
 *    editable status (ProjectService::allowedActions()['edit']) — the same
 *    rule as editing the project itself.
 * Changes go to the project's timeline (Core TimelineService).
 * Errors are ValidationException with translation keys, like ProjectService.
 */
final class ProjectMilestoneService
{
    public const STATUSES = ['planned', 'completed', 'cancelled'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectMilestoneRepository $milestones,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
    ) {
    }

    public function forProject(array $actingUser, int $projectId): array
    {
        $this->projects->find($actingUser, $projectId);

        return $this->milestones->forProject($projectId);
    }

    /** One milestone of the project, for its edit form (write access required). */
    public function find(array $actingUser, int $projectId, int $milestoneId): array
    {
        $this->writableProject($actingUser, $projectId);

        return $this->require($projectId, $milestoneId);
    }

    /** @return array{total: int, completed: int, next: ?array} for the overview card */
    public function summary(array $milestones, ?string $today = null): array
    {
        $today ??= OrganizationTimezone::shared()->today();
        $next = null;
        foreach ($milestones as $m) {
            if ($m['status'] === 'planned' && $m['date'] >= $today) {
                $next = $m;
                break;
            }
        }

        return [
            'total' => count($milestones),
            'completed' => count(array_filter($milestones, fn (array $m) => $m['status'] === 'completed')),
            'next' => $next,
        ];
    }

    public function create(array $actingUser, int $projectId, array $input): array
    {
        $this->writableProject($actingUser, $projectId);
        $fields = $this->validated($input);

        $id = $this->milestones->create($projectId, $fields);
        $this->timeline->record('project', $projectId, 'milestone_created', 'Milestone created: "' . $fields['name'] . '"', (int) $actingUser['id']);

        return $this->require($projectId, $id);
    }

    public function update(array $actingUser, int $projectId, int $milestoneId, array $input): array
    {
        $this->writableProject($actingUser, $projectId);
        $current = $this->require($projectId, $milestoneId);
        $fields = $this->validated($input);

        $this->milestones->updateMilestone($milestoneId, $fields);
        $this->timeline->record('project', $projectId, 'milestone_updated', 'Milestone updated: "' . $fields['name'] . '"'
            . ($current['status'] !== $fields['status'] ? ' (' . $current['status'] . ' → ' . $fields['status'] . ')' : ''), (int) $actingUser['id']);

        return $this->require($projectId, $milestoneId);
    }

    public function delete(array $actingUser, int $projectId, int $milestoneId): void
    {
        $this->writableProject($actingUser, $projectId);
        $milestone = $this->require($projectId, $milestoneId);

        // Only when THIS request removed the row (a concurrent delete leaves no second entry).
        if ($this->milestones->deleteMilestone($milestoneId) === 1) {
            $this->timeline->record('project', $projectId, 'milestone_deleted', 'Milestone deleted: "' . $milestone['name'] . '"', (int) $actingUser['id']);
        }
    }

    /** May this user add/edit/delete milestones of this (already loaded) project right now? */
    public function canManage(array $actingUser, array $project): bool
    {
        return $this->projects->allowedActions($actingUser, $project)['edit'];
    }

    // ---- Internals ---------------------------------------------------------

    /** 404 for another organization's / unknown project, 403 without view or projects.edit, a validation error when the project is closed. */
    private function writableProject(array $actingUser, int $projectId): array
    {
        $project = $this->projects->find($actingUser, $projectId);
        if (!$this->authorization->can($actingUser, ProjectPermission::EDIT, $project)) {
            throw new AuthorizationException();
        }
        if (!$this->canManage($actingUser, $project)) {
            throw new ValidationException(['status' => ['projects.error.not_editable']]);
        }

        return $project;
    }

    private function require(int $projectId, int $milestoneId): array
    {
        $milestone = $this->milestones->find($projectId, $milestoneId);
        if ($milestone === null) {
            throw new NotFoundException('Milestone not found.');
        }

        return $milestone;
    }

    private function validated(array $input): array
    {
        $errors = [];
        $text = fn (string $key) => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        $name = $text('name');
        if ($name === '') {
            $errors['name'][] = 'projects.milestones.error.name_required';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'][] = 'projects.error.name_too_long';
        }

        $date = $text('date');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date === '') {
            $errors['date'][] = 'projects.milestones.error.date_required';
        } elseif ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors['date'][] = 'projects.error.invalid_date';
        }

        $status = $text('status') === '' ? 'planned' : $text('status');
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'][] = 'projects.milestones.error.invalid_status';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['name' => $name, 'date' => $date, 'status' => $status];
    }
}
