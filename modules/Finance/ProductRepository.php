<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class ProductRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'products';
    }

    /**
     * Deliberately NOT tenantFindMany() here: this query's SELECT list
     * has correlated subqueries with their OWN "WHERE" clauses that
     * appear textually before the outer WHERE. tenantFindMany()'s
     * auto-scoping replaces only the FIRST "WHERE" in the string —
     * it would wrongly land inside a subquery instead of the outer
     * query, which is both a broken query and a cross-tenant leak
     * (the outer query would end up with no org scoping at all).
     * Same reasoning, same fix, as ContactRepository::search().
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = 'SELECT p.*,
                    (SELECT code FROM currencies WHERE currencies.id = p.currency_id) AS currency_code,
                    (SELECT name FROM tax_categories WHERE tax_categories.id = p.tax_category_id) AS tax_category_name
                FROM products p
                WHERE ' . ($orgScoped ? 'p.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['search'])) {
            $sql .= ' AND (p.name LIKE :search OR p.sku LIKE :search2)';
            $like = '%' . $filters['search'] . '%';
            $params['search'] = $like;
            $params['search2'] = $like;
        }

        // Where the tax product ID came from: the national list, or typed in / none.
        if (($filters['tax_source'] ?? '') === 'catalog') {
            $sql .= " AND p.tax_product_source = 'catalog'";
        } elseif (($filters['tax_source'] ?? '') === 'manual') {
            $sql .= " AND (p.tax_product_source IS NULL OR p.tax_product_source <> 'catalog')";
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $sql .= ' AND p.is_active = :is_active';
            $params['is_active'] = $filters['is_active'] ? 1 : 0;
        }

        $sql .= ' ORDER BY p.name';

        return $this->findMany($sql, $params);
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM products WHERE id = :id', ['id' => $id]);
    }

    /** Unit of the organization's newest product with this tax product ID (null when none has one). */
    public function unitForTaxCode(string $code): ?string
    {
        $row = $this->tenantFindOne(
            "SELECT unit FROM products WHERE tax_product_code = :code AND unit IS NOT NULL AND unit <> '' ORDER BY id DESC LIMIT 1",
            ['code' => $code]
        );

        return $row['unit'] ?? null;
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateProduct(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteProduct(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
