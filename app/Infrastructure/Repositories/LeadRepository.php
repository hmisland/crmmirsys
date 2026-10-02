<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class LeadRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'leads';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array{search?: string, status?: string, owner_id?: int} $filters
     *
     * Distinct placeholder per LIKE clause — see CompanyRepository::search()'s
     * docblock for why the same named parameter can't be reused with
     * real (non-emulated) prepared statements.
     */
    public function search(array $filters = []): array
    {
        $sql = 'SELECT * FROM leads WHERE 1 = 1';
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= ' AND (first_name LIKE :search1 OR last_name LIKE :search2 OR email LIKE :search3 OR company_name LIKE :search4)';
            $like = '%' . $filters['search'] . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
            $params['search4'] = $like;
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        $sql .= ' ORDER BY created_at DESC';

        return $this->tenantFindMany($sql, $params);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateLead(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteLead(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
