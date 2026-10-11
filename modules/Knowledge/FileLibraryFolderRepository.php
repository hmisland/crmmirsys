<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class FileLibraryFolderRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'file_library_folders';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM file_library_folders WHERE id = :id', ['id' => $id]);
    }

    /** Every folder of the organization, for breadcrumbs and the "move to" list. */
    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM file_library_folders WHERE 1 = 1 ORDER BY name');
    }

    /**
     * Direct children of $parentId (NULL = top level), each with its
     * own item count. The counts' subqueries carry their own
     * organization filter, so the outer WHERE is org-qualified by hand
     * like FileLibraryDocumentRepository::all().
     */
    public function children(?int $parentId): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = ($orgScoped ? 'f.organization_id = :org_id AND ' : '') . ($parentId === null ? 'f.parent_id IS NULL' : 'f.parent_id = :parent_id');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];
        if ($parentId !== null) {
            $params['parent_id'] = $parentId;
        }

        return $this->findMany(
            "SELECT f.*,
                (SELECT COUNT(*) FROM file_library_folders c WHERE c.parent_id = f.id)
                + (SELECT COUNT(*) FROM file_library_documents d WHERE d.folder_id = f.id) AS item_count
             FROM file_library_folders f
             WHERE {$where}
             ORDER BY f.name",
            $params
        );
    }

    public function findByName(?int $parentId, string $name): ?array
    {
        if ($parentId === null) {
            return $this->tenantFindOne('SELECT * FROM file_library_folders WHERE parent_id IS NULL AND name = :name', ['name' => $name]);
        }

        return $this->tenantFindOne('SELECT * FROM file_library_folders WHERE parent_id = :parent_id AND name = :name', ['parent_id' => $parentId, 'name' => $name]);
    }

    public function hasChildren(int $id): bool
    {
        return $this->tenantFindOne('SELECT id FROM file_library_folders WHERE parent_id = :id LIMIT 1', ['id' => $id]) !== null;
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function rename(int $id, string $name): void
    {
        $this->tenantUpdate(['name' => $name], 'id = :id', ['id' => $id]);
    }

    public function deleteFolder(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
