<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * project_members (module migration 0002) — tenant-scoped like every other
 * table. forProject() joins users, so it scopes organization_id explicitly.
 */
final class ProjectMemberRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'project_members';
    }

    /** Members with their names, oldest first. */
    public function forProject(int $projectId): array
    {
        return $this->findMany(
            "SELECT pm.*, u.first_name, u.last_name, u.email, u.status AS user_status
             FROM project_members pm
             JOIN users u ON u.id = pm.user_id
             WHERE pm.organization_id = :org_id AND pm.project_id = :project_id
             ORDER BY pm.created_at, pm.id",
            ['org_id' => OrganizationContext::id(), 'project_id' => $projectId]
        );
    }

    public function find(int $projectId, int $userId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM project_members WHERE project_id = :project_id AND user_id = :user_id',
            ['project_id' => $projectId, 'user_id' => $userId]
        );
    }

    public function isMember(int $projectId, int $userId): bool
    {
        return $this->find($projectId, $userId) !== null;
    }

    public function create(int $projectId, int $userId, ?int $addedBy): int
    {
        return $this->tenantInsertGetId([
            'project_id' => $projectId,
            'user_id' => $userId,
            'added_by_user_id' => $addedBy,
        ]);
    }

    public function deleteMember(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
