<?php

declare(strict_types=1);

namespace Modules\Knowledge;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class KbArticleRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'kb_articles';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM kb_articles WHERE id = :id', ['id' => $id]);
    }

    /**
     * Correlated subqueries for category/author names, each with
     * their own WHERE — explicitly org-qualified rather than relying
     * on tenantFindMany()'s automatic first-WHERE-only scoping, the
     * established fix applied from the start on every module since
     * Support.
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'kb_articles.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        $sql = 'SELECT kb_articles.*,
                    (SELECT name FROM kb_categories WHERE kb_categories.id = kb_articles.category_id) AS category_name,
                    (SELECT CONCAT(first_name, " ", COALESCE(last_name, "")) FROM users WHERE users.id = kb_articles.created_by_user_id) AS author_name
                FROM kb_articles
                WHERE ' . $where;

        if (!empty($filters['category_id'])) {
            $sql .= ' AND kb_articles.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (kb_articles.title LIKE :search1 OR kb_articles.body LIKE :search2)';
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY kb_articles.updated_at DESC';

        return $this->findMany($sql, $params);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateArticle(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteArticle(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
