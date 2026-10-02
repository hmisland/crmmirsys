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
    public function search(array $filters = []): array
    {
        $sql = 'SELECT * FROM companies WHERE 1 = 1';
        $params = [];

        if (!empty($filters['search'])) {
            $sql .= ' AND (name LIKE :search1 OR email LIKE :search2 OR website LIKE :search3)';
            $like = '%' . $filters['search'] . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
        }

        $sql .= ' ORDER BY name';

        return $this->tenantFindMany($sql, $params);
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
