<?php

declare(strict_types=1);

namespace Modules\Support;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class TicketRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'tickets';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM tickets WHERE id = :id', ['id' => $id]);
    }

    /**
     * Correlated subqueries for contact/category/assignee names, each
     * with its own WHERE — so (per ContactRepository::search()'s
     * docblock, hit for real in Phase 5, and twice more since) this
     * deliberately does NOT use tenantFindMany()'s automatic
     * first-WHERE-only scoping. The org condition is built explicitly
     * and qualified to `tickets` instead.
     *
     * @param array{status?: string, assigned_to_user_id?: int, search?: string} $filters
     */
    /**
     * "Needs a response" = assigned to this user and not yet
     * resolved/closed — a dedicated method rather than overloading
     * search() with an IN-list status filter it doesn't support,
     * the same "purpose-specific method" pattern already used for
     * Tasks' upcomingForUser().
     */
    public function openAssignedToUser(int $userId): array
    {
        return $this->tenantFindMany(
            "SELECT * FROM tickets WHERE assigned_to_user_id = :user_id AND status IN ('open', 'pending') ORDER BY created_at DESC",
            ['user_id' => $userId]
        );
    }

    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $where = $orgScoped ? 'tickets.organization_id = :org_id' : '1 = 1';
        $params = $orgScoped ? ['org_id' => OrganizationContext::id()] : [];

        $sql = 'SELECT tickets.*,
                    (SELECT CONCAT(first_name, " ", COALESCE(last_name, "")) FROM contacts WHERE contacts.id = tickets.contact_id) AS contact_name,
                    (SELECT name FROM ticket_categories WHERE ticket_categories.id = tickets.category_id) AS category_name,
                    (SELECT CONCAT(first_name, " ", COALESCE(last_name, "")) FROM users WHERE users.id = tickets.assigned_to_user_id) AS assignee_name
                FROM tickets
                WHERE ' . $where;

        if (!empty($filters['status'])) {
            $sql .= ' AND tickets.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['assigned_to_user_id'])) {
            $sql .= ' AND tickets.assigned_to_user_id = :assigned_to_user_id';
            $params['assigned_to_user_id'] = $filters['assigned_to_user_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND tickets.subject LIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY tickets.created_at DESC';

        return $this->findMany($sql, $params);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateTicket(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteTicket(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
