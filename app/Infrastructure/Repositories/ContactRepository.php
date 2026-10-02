<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ContactRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contacts';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contacts WHERE id = :id', ['id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contacts WHERE email = :email', ['email' => $email]);
    }

    /**
     * @param array{search?: string, company_id?: int, owner_id?: int} $filters
     *
     * Deliberately does NOT go through tenantFindMany() here: that
     * helper finds the FIRST "WHERE" in the SQL text and injects the
     * org filter there — correct for a simple query, but this query's
     * correlated subquery (for company_name) contains its own WHERE
     * that appears earlier in the string, so the automatic injection
     * would scope the subquery instead of the outer query, silently
     * leaking contacts across organizations. The org condition is
     * built explicitly here instead, qualified to `contacts`, and
     * passed to the raw (non-scoping) findMany().
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();

        $sql = 'SELECT contacts.*,
                    (SELECT name FROM companies WHERE companies.id = contacts.company_id) AS company_name,
                    (SELECT name FROM contact_categories WHERE contact_categories.id = contacts.category_id) AS category_name
                FROM contacts
                WHERE ' . ($orgScoped ? 'contacts.organization_id = :org_id' : '1 = 1');
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        if (!empty($filters['search'])) {
            $sql .= ' AND (first_name LIKE :search1 OR last_name LIKE :search2 OR email LIKE :search3)';
            $like = '%' . $filters['search'] . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
        }

        if (!empty($filters['company_id'])) {
            $sql .= ' AND company_id = :company_id';
            $params['company_id'] = $filters['company_id'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= ' AND contacts.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        if (!empty($filters['owner_id'])) {
            $sql .= ' AND owner_id = :owner_id';
            $params['owner_id'] = $filters['owner_id'];
        }

        $sql .= ' ORDER BY first_name, last_name';

        return $this->findMany($sql, $params);
    }

    public function countAll(): int
    {
        $row = $this->tenantFindOne('SELECT COUNT(*) AS c FROM contacts WHERE 1 = 1');

        return (int) ($row['c'] ?? 0);
    }

    public function countForCompany(int $companyId): int
    {
        $row = $this->tenantFindOne(
            'SELECT COUNT(*) AS c FROM contacts WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );

        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateContact(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteContact(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
