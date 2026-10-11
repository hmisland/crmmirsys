<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class CompanyRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'companies';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM companies WHERE id = :id', ['id' => $id]);
    }

    public function countAll(): int
    {
        $row = $this->tenantFindOne('SELECT COUNT(*) AS c FROM companies WHERE 1 = 1');

        return (int) ($row['c'] ?? 0);
    }

    public function all(): array
    {
        return $this->search([]);
    }

    /**
     * @param array{search?: string} $filters
     *
     * Uses a distinct placeholder per LIKE clause (:search1/2/3)
     * rather than reusing :search three times — PDO with real
     * (non-emulated) prepared statements, which Connection.php uses
     * deliberately for security, does not allow the same named
     * parameter to appear more than once in one query and throws
     * "SQLSTATE[HY093]: Invalid parameter number" if it does.
     */
    public function search(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->searchConditions($filters);
        $sql = 'SELECT * FROM companies WHERE ' . $where . ' ORDER BY name, id';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return $this->tenantFindMany($sql, $params);
    }

    public function countSearch(array $filters = []): int
    {
        [$where, $params] = $this->searchConditions($filters);
        $row = $this->tenantFindOne('SELECT COUNT(*) AS c FROM companies WHERE ' . $where, $params);

        return (int) ($row['c'] ?? 0);
    }

    /** @return array{0: string, 1: array} */
    private function searchConditions(array $filters): array
    {
        $where = '1 = 1';
        $params = [];

        if (!empty($filters['search'])) {
            $where .= ' AND (name LIKE :search1 OR email LIKE :search2 OR website LIKE :search3 OR phone LIKE :search4 OR brand LIKE :search5 OR national_id LIKE :search6)';
            $like = '%' . $filters['search'] . '%';
            foreach ([1, 2, 3, 4, 5, 6] as $n) {
                $params['search' . $n] = $like;
            }
        }

        if (!empty($filters['owner_id'])) {
            $where .= ' AND owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        if (!empty($filters['category_id'])) {
            $where .= ' AND category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        return [$where, $params];
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateCompany(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteCompany(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
