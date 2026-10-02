<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class ProformaRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'proformas';
    }

    /**
     * Deliberately NOT tenantFindMany(): the SELECT list's correlated
     * subqueries have their own WHERE clauses textually before the
     * outer one, which would confuse tenantFindMany()'s naive
     * first-WHERE auto-scoping — same bug class, same fix, as
     * ProductRepository::search().
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT p.*,
                    (SELECT code FROM currencies WHERE currencies.id = p.currency_id) AS currency_code,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM contacts WHERE contacts.id = p.contact_id) AS contact_name,
                    (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = p.owner_id) AS owner_name
                FROM proformas p
                WHERE " . ($orgScoped ? 'p.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND p.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' ORDER BY p.created_at DESC';

        return $this->findMany($sql, $params);
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM proformas WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateProforma(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteProforma(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
