<?php

declare(strict_types=1);

namespace Modules\Appointments;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * appointments. Joined queries are org-qualified explicitly (a.*, c.*)
 * instead of going through tenantFindMany(), whose automatic filter
 * targets the first WHERE and an unqualified organization_id column.
 */
final class AppointmentRepository extends TenantScopedRepository
{
    /** Statuses that occupy their time slot (the rest free it again). */
    public const BLOCKING_STATUSES = ['requested', 'scheduled', 'confirmed', 'in_progress', 'completed'];

    private const SELECT = "SELECT a.*,
                c.first_name AS contact_first_name, c.last_name AS contact_last_name,
                c.phone AS contact_phone, c.email AS contact_email,
                t.name AS type_name, t.buffer_minutes AS type_buffer_minutes,
                r.name AS resource_name, r.kind AS resource_kind
            FROM appointments a
            LEFT JOIN contacts c ON c.id = a.contact_id AND c.organization_id = a.organization_id
            LEFT JOIN appointment_types t ON t.id = a.appointment_type_id AND t.organization_id = a.organization_id
            LEFT JOIN appointment_resources r ON r.id = a.resource_id AND r.organization_id = a.organization_id";

    protected function table(): string
    {
        return 'appointments';
    }

    public function findById(int $id): ?array
    {
        $params = ['id' => $id];

        return $this->findOne(self::SELECT . ' WHERE ' . $this->orgClause($params) . ' AND a.id = :id', $params);
    }

    /**
     * Filters: from / to (DATETIME, on starts_at), resource_id,
     * assigned_user_id, contact_id, status, q, limit — plus the RBAC
     * narrowing keys (visible_to_user_id / team_id / department_id) set
     * by AppointmentService, never taken from request input.
     */
    public function search(array $filters = []): array
    {
        $params = [];
        $sql = self::SELECT . ' WHERE ' . $this->orgClause($params);

        if (!empty($filters['from'])) {
            $sql .= ' AND a.starts_at >= :from';
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $sql .= ' AND a.starts_at < :to';
            $params['to'] = $filters['to'];
        }

        foreach (['resource_id', 'assigned_user_id', 'contact_id', 'appointment_type_id'] as $key) {
            if (!empty($filters[$key])) {
                $sql .= " AND a.{$key} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND a.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if (!empty($filters['active_only'])) {
            $sql .= " AND a.status NOT IN ('cancelled', 'no_show')";
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $sql .= " AND (a.title LIKE :q1 OR c.first_name LIKE :q2 OR c.last_name LIKE :q3
                        OR CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) LIKE :q4 OR c.phone LIKE :q5)";
            foreach (range(1, 5) as $n) {
                $params['q' . $n] = '%' . $term . '%';
            }
        }

        if (!empty($filters['visible_to_user_id'])) {
            $sql .= ' AND (a.owner_id = :vis_owner OR a.assigned_user_id = :vis_assigned)';
            $params['vis_owner'] = (int) $filters['visible_to_user_id'];
            $params['vis_assigned'] = (int) $filters['visible_to_user_id'];
        }

        if (array_key_exists('team_id', $filters)) {
            $sql .= ' AND a.team_id = :team_id';
            $params['team_id'] = (int) $filters['team_id'];
        }

        if (array_key_exists('department_id', $filters)) {
            $sql .= ' AND a.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }

        $order = !empty($filters['newest_first']) ? 'DESC' : 'ASC';
        $sql .= " ORDER BY a.starts_at {$order}, a.id {$order} LIMIT " . max(1, min(2000, (int) ($filters['limit'] ?? 500)));

        return $this->findMany($sql, $params);
    }

    /**
     * How many slot-occupying appointments on a resource overlap
     * [$start, $end). Each existing appointment occupies its own time
     * plus its type's buffer; $end already includes the new one's.
     */
    public function countOverlappingOnResource(int $resourceId, string $start, string $end, ?int $excludeId): int
    {
        $params = ['resource_id' => $resourceId];

        return $this->countOverlapping('a.resource_id = :resource_id', $params, $start, $end, $excludeId);
    }

    public function countOverlappingForUser(int $userId, string $start, string $end, ?int $excludeId): int
    {
        $params = ['user_id' => $userId];

        return $this->countOverlapping('a.assigned_user_id = :user_id', $params, $start, $end, $excludeId);
    }

    /** Appointments still pointing at a resource or type (any status), so deleting it would orphan history. */
    public function countUsing(string $column, int $id): int
    {
        $column = $column === 'resource_id' ? 'resource_id' : 'appointment_type_id';
        $row = $this->tenantFindOne("SELECT COUNT(*) AS n FROM appointments WHERE {$column} = :id", ['id' => $id]);

        return (int) ($row['n'] ?? 0);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateAppointment(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteAppointment(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }

    private function countOverlapping(string $condition, array $params, string $start, string $end, ?int $excludeId): int
    {
        $statuses = [];
        foreach (self::BLOCKING_STATUSES as $i => $status) {
            $statuses[] = ':st' . $i;
            $params['st' . $i] = $status;
        }

        $sql = 'SELECT COUNT(*) AS n FROM appointments a
                LEFT JOIN appointment_types t ON t.id = a.appointment_type_id AND t.organization_id = a.organization_id
                WHERE ' . $this->orgClause($params) . ' AND ' . $condition . '
                  AND a.status IN (' . implode(', ', $statuses) . ')
                  AND a.starts_at < :range_end
                  AND DATE_ADD(a.ends_at, INTERVAL COALESCE(t.buffer_minutes, 0) MINUTE) > :range_start';
        $params['range_start'] = $start;
        $params['range_end'] = $end;

        if ($excludeId !== null) {
            $sql .= ' AND a.id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        return (int) ($this->findOne($sql, $params)['n'] ?? 0);
    }

    private function orgClause(array &$params): string
    {
        if (SuperAdminContext::isActive()) {
            return '1 = 1';
        }

        $params['org_id'] = OrganizationContext::id();

        return 'a.organization_id = :org_id';
    }
}
