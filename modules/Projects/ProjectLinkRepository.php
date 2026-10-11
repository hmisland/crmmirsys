<?php

declare(strict_types=1);

namespace Modules\Projects;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** project_links (module migration 0004) — tenant-scoped like every other table. */
final class ProjectLinkRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'project_links';
    }

    public function forProject(int $projectId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM project_links WHERE project_id = :project_id ORDER BY created_at, id',
            ['project_id' => $projectId]
        );
    }

    /** @return int[] project ids linked to one record of another module */
    public function projectIdsLinkedTo(string $linkedType, int $linkedId): array
    {
        $rows = $this->tenantFindMany(
            'SELECT project_id FROM project_links WHERE linked_type = :type AND linked_id = :id ORDER BY project_id DESC',
            ['type' => $linkedType, 'id' => $linkedId]
        );

        return array_map(fn (array $r) => (int) $r['project_id'], $rows);
    }

    public function find(int $projectId, string $linkedType, int $linkedId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM project_links WHERE project_id = :project_id AND linked_type = :type AND linked_id = :id',
            ['project_id' => $projectId, 'type' => $linkedType, 'id' => $linkedId]
        );
    }

    public function create(int $projectId, string $linkedType, int $linkedId, string $label, ?int $userId): int
    {
        return $this->tenantInsertGetId([
            'project_id' => $projectId,
            'linked_type' => $linkedType,
            'linked_id' => $linkedId,
            'linked_label' => mb_substr($label, 0, 255),
            'created_by_user_id' => $userId,
        ]);
    }

    /** @return int rows deleted: 1, or 0 when a concurrent request removed it first */
    public function deleteLink(int $id): int
    {
        return $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
