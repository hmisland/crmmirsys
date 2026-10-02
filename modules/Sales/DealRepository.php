<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class DealRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'deals';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM deals WHERE id = :id', ['id' => $id]);
    }

    public function forPipeline(int $pipelineId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM deals WHERE pipeline_id = :pipeline_id AND status = "open" ORDER BY created_at DESC',
            ['pipeline_id' => $pipelineId]
        );
    }

    /**
     * Deliberately raw (not tenantFindMany): the correlated subqueries
     * for contact/company names each contain their own WHERE, and
     * tenantFindMany's automatic scoping only ever patches the FIRST
     * WHERE it finds in the SQL text — which would be one of these
     * subqueries, not the outer query (see ContactRepository::search()'s
     * docblock for the same issue, hit for real during Phase 5 testing).
     * The org condition is built explicitly and qualified here instead.
     *
     * @param array{search?: string, status?: string, owner_id?: int} $filters
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();

        $sql = 'SELECT deals.*,
                    (SELECT CONCAT(first_name, " ", COALESCE(last_name, "")) FROM contacts WHERE contacts.id = deals.contact_id) AS contact_name,
                    (SELECT name FROM companies WHERE companies.id = deals.company_id) AS company_name
                FROM deals
                WHERE ' . ($orgScoped ? 'deals.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['search'])) {
            $sql .= ' AND deals.name LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND deals.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND deals.owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        $sql .= ' ORDER BY deals.created_at DESC';

        return $this->findMany($sql, $params);
    }

    /** @return array<int, array{stage_id: int, total: string, count: int}> */
    public function totalsByStage(int $pipelineId): array
    {
        return $this->tenantFindMany(
            'SELECT stage_id, SUM(value) AS total, COUNT(*) AS count
             FROM deals WHERE pipeline_id = :pipeline_id AND status = "open"
             GROUP BY stage_id',
            ['pipeline_id' => $pipelineId]
        );
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateDeal(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteDeal(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
