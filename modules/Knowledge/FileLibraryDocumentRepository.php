<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class FileLibraryDocumentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'file_library_documents';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM file_library_documents WHERE id = :id', ['id' => $id]);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $key = "id{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = (int) $id;
        }

        return $this->listWhere('file_library_documents.id IN (' . implode(',', $placeholders) . ')', $params);
    }

    /**
     * Correlated subquery for the uploader's name — own WHERE, so
     * explicitly org-qualified rather than tenantFindMany()'s
     * automatic scoping, same reasoning as every other module.
     */
    public function all(): array
    {
        return $this->listWhere('1 = 1', []);
    }

    /** Documents directly inside $folderId (NULL = top level). */
    public function inFolder(?int $folderId): array
    {
        return $folderId === null
            ? $this->listWhere('file_library_documents.folder_id IS NULL', [])
            : $this->listWhere('file_library_documents.folder_id = :folder_id', ['folder_id' => $folderId]);
    }

    /** Title / original filename contains $term, across all folders. */
    public function searchByName(string $term): array
    {
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $this->listWhere(
            '(file_library_documents.title LIKE :term1 OR file_library_documents.original_filename LIKE :term2)',
            ['term1' => $like, 'term2' => $like]
        );
    }

    public function hasDocumentsInFolder(int $folderId): bool
    {
        return $this->tenantFindOne('SELECT id FROM file_library_documents WHERE folder_id = :folder_id LIMIT 1', ['folder_id' => $folderId]) !== null;
    }

    public function moveToFolder(int $id, ?int $folderId): void
    {
        $this->tenantUpdate(['folder_id' => $folderId], 'id = :id', ['id' => $id]);
    }

    private function listWhere(string $condition, array $params): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = ($orgScoped ? 'file_library_documents.organization_id = :org_id AND ' : '') . $condition;
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        return $this->findMany(
            "SELECT file_library_documents.*,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = file_library_documents.uploaded_by_user_id) AS uploader_name
             FROM file_library_documents
             WHERE {$where}
             ORDER BY file_library_documents.created_at DESC, file_library_documents.id DESC",
            $params
        );
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function deleteDocument(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
