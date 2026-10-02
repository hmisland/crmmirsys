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
            $params[$key] = $id;
        }

        return $this->tenantFindMany(
            'SELECT * FROM file_library_documents WHERE id IN (' . implode(',', $placeholders) . ') ORDER BY created_at DESC',
            $params
        );
    }

    /**
     * Correlated subquery for the uploader's name — own WHERE, so
     * explicitly org-qualified rather than tenantFindMany()'s
     * automatic scoping, same reasoning as every other module.
     */
    public function all(): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'file_library_documents.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        return $this->findMany(
            "SELECT file_library_documents.*,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = file_library_documents.uploaded_by_user_id) AS uploader_name
             FROM file_library_documents
             WHERE {$where}
             ORDER BY file_library_documents.created_at DESC",
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
