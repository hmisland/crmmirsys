<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * project_milestones (module migration 0006) — tenant-scoped: another
 * organization's milestone is never found, updated or deleted.
 */
final class ProjectMilestoneRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'project_milestones';
    }

    /** A project's milestones, earliest date first. */
    public function forProject(int $projectId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM project_milestones WHERE project_id = :project_id ORDER BY date, id',
            ['project_id' => $projectId]
        );
    }

    /** One milestone of THIS project (an id of another project or organization is "not found"). */
    public function find(int $projectId, int $id): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM project_milestones WHERE id = :id AND project_id = :project_id',
            ['id' => $id, 'project_id' => $projectId]
        );
    }

    public function create(int $projectId, array $fields): int
    {
        return $this->tenantInsertGetId(['project_id' => $projectId] + $fields);
    }

    public function updateMilestone(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteMilestone(int $id): int
    {
        return $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
