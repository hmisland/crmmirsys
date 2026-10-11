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
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->searchConditions($filters);
        $sql = 'SELECT * FROM leads WHERE ' . $where . ' ORDER BY created_at DESC, id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->tenantFindMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->searchConditions($filters);
        $row = $this->tenantFindOne('SELECT COUNT(*) AS c FROM leads WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /** @return array{0: string, 1: array} */
    private function searchConditions(array $filters): array
    {
        $where = '1 = 1';
        $params = [];

        if (!empty($filters['search'])) {
            $where .= ' AND (first_name LIKE :search1 OR last_name LIKE :search2 OR email LIKE :search3 OR company_name LIKE :search4 OR phone LIKE :search5)';
            $like = '%' . $filters['search'] . '%';
            foreach ([1, 2, 3, 4, 5] as $n) {
                $params['search' . $n] = $like;
            }
        }

        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['owner_id'])) {
            $where .= ' AND owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        return [$where, $params];
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
