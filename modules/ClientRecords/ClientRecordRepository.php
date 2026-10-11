<?php

declare(strict_types=1);

namespace Modules\ClientRecords;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

final class ClientRecordRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'client_records';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM client_records WHERE id = :id', ['id' => $id]);
    }

    public function findByContact(int $contactId): ?array
    {
        return $this->tenantFindOne('SELECT * FROM client_records WHERE contact_id = :contact_id', ['contact_id' => $contactId]);
    }

    /**
     * The list/search query. Deliberately NOT tenantFindMany(): it joins
     * contacts and has correlated subqueries, so the org condition is
     * written explicitly and qualified (same reasoning as
     * ContactRepository::search()). The contact join repeats the org
     * condition so a mismatched contact_id could never surface another
     * tenant's contact data.
     *
     * @param array{q?: string, status?: string, assigned_user_id?: int,
     *   visible_to_user_id?: int, team_id?: int, department_id?: int,
     *   contact_id?: int, limit?: int} $filters
     */
    public function search(array $filters = []): array
    {
        $orgScoped = !SuperAdminContext::isActive();
        $sql = "SELECT r.*,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name,
                    c.phone AS contact_phone, c.email AS contact_email,
                    (SELECT MAX(s.session_at) FROM client_record_sessions s
                        WHERE s.client_record_id = r.id AND s.status = 'completed') AS last_visit_at,
                    (SELECT MIN(s2.session_at) FROM client_record_sessions s2
                        WHERE s2.client_record_id = r.id AND s2.status = 'scheduled' AND s2.session_at >= :now) AS next_visit_at
                FROM client_records r
                INNER JOIN contacts c ON c.id = r.contact_id AND c.organization_id = r.organization_id
                WHERE " . ($orgScoped ? 'r.organization_id = :org_id' : '1 = 1');
        $params = ['now' => date('Y-m-d H:i:s')];
        if ($orgScoped) {
            $params['org_id'] = OrganizationContext::id();
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . $term . '%';
            $sql .= " AND (r.record_number LIKE :q1
                        OR c.first_name LIKE :q2 OR c.last_name LIKE :q3
                        OR CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) LIKE :q4
                        OR c.phone LIKE :q5 OR c.email LIKE :q6
                        OR r.id IN (SELECT sv.client_record_id FROM client_record_services sv
                                    WHERE sv.organization_id = r.organization_id AND sv.title LIKE :q7)
                        OR r.id IN (SELECT ss.client_record_id FROM client_record_sessions ss
                                    WHERE ss.organization_id = r.organization_id AND ss.title LIKE :q8))";
            foreach (range(1, 8) as $n) {
                $params['q' . $n] = $like;
            }
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND r.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['assigned_user_id'])) {
            $sql .= ' AND r.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }

        if (!empty($filters['contact_id'])) {
            $sql .= ' AND r.contact_id = :contact_id';
            $params['contact_id'] = (int) $filters['contact_id'];
        }

        // RBAC scope narrowing, decided by ClientRecordService from
        // AuthorizationEngine::scopeFor() — never from request input.
        if (!empty($filters['visible_to_user_id'])) {
            $sql .= ' AND (r.owner_id = :vis_owner OR r.assigned_user_id = :vis_assigned)';
            $params['vis_owner'] = (int) $filters['visible_to_user_id'];
            $params['vis_assigned'] = (int) $filters['visible_to_user_id'];
        }

        if (array_key_exists('team_id', $filters)) {
            $sql .= ' AND r.team_id = :team_id';
            $params['team_id'] = (int) $filters['team_id'];
        }

        if (array_key_exists('department_id', $filters)) {
            $sql .= ' AND r.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }

        $sql .= ' ORDER BY r.updated_at DESC LIMIT ' . max(1, min(1000, (int) ($filters['limit'] ?? 500)));

        return $this->findMany($sql, $params);
    }

    /**
     * Next per-organization record number (CR-000001, CR-000002, ...).
     * Per tenant, not the global id, so one organization can't infer
     * how many records the platform holds. Uniqueness is guaranteed by
     * UNIQUE(organization_id, record_number); the service retries on a
     * concurrent collision.
     */
    public function nextRecordNumber(): string
    {
        $row = $this->tenantFindOne(
            "SELECT MAX(CAST(SUBSTRING(record_number, 4) AS UNSIGNED)) AS n FROM client_records WHERE record_number LIKE 'CR-%'"
        );

        return 'CR-' . str_pad((string) ((int) ($row['n'] ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function create(array $fields): int
    {
        return $this->tenantInsertGetId($fields);
    }

    public function updateRecord(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function deleteRecord(int $id): void
    {
        $this->tenantDelete('id = :id', ['id' => $id]);
    }
}
