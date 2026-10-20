<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * module_activation_requests (migration 0088). Organization-side reads
 * always take the organization id explicitly; the Super Admin list is the
 * only cross-organization read (callers check is_super_admin).
 */
final class ModuleActivationRequestRepository extends BaseRepository
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** @return int|null the new id, or null when a pending request for this organization + module already exists */
    public function createPending(int $organizationId, int $moduleId, string $moduleKey, int $userId): ?int
    {
        try {
            return $this->insertGetId('module_activation_requests', [
                'organization_id' => $organizationId,
                'module_id' => $moduleId,
                'module_key' => $moduleKey,
                'status' => self::PENDING,
                'pending_key' => self::pendingKey($organizationId, $moduleId),
                'requested_by_user_id' => $userId,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<int, array> module_id => the organization's latest request for that module */
    public function latestForOrganization(int $organizationId): array
    {
        $latest = [];
        foreach ($this->findMany(
            'SELECT * FROM module_activation_requests WHERE organization_id = :org ORDER BY id DESC',
            ['org' => $organizationId]
        ) as $row) {
            $latest[(int) $row['module_id']] ??= $row;
        }

        return $latest;
    }

    /** One request row, locked for a review (inside a transaction). */
    public function findForUpdate(int $id): ?array
    {
        return $this->findOne('SELECT * FROM module_activation_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        return $this->findOne('SELECT * FROM module_activation_requests WHERE id = :id', ['id' => $id]);
    }

    /** Records the decision; only while still pending (a second, conflicting review changes nothing). */
    public function decide(int $id, string $status, int $reviewerId, ?string $reason): bool
    {
        return $this->update('module_activation_requests', [
            'status' => $status,
            'pending_key' => null,
            'reviewed_by_user_id' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
        ], "id = :id AND status = 'pending'", ['id' => $id]) > 0;
    }

    /**
     * Super Admin list: pending first (oldest first), then reviewed (newest first).
     *
     * @param array{status?: string, organization_id?: int} $filters
     */
    public function adminPage(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany(
            "SELECT r.*, o.name AS organization_name, m.name AS module_name,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS requester_name, u.email AS requester_email,
                    TRIM(CONCAT(COALESCE(v.first_name, ''), ' ', COALESCE(v.last_name, ''))) AS reviewer_name
             FROM module_activation_requests r
             JOIN organizations o ON o.id = r.organization_id
             JOIN modules m ON m.id = r.module_id
             LEFT JOIN users u ON u.id = r.requested_by_user_id
             LEFT JOIN users v ON v.id = r.reviewed_by_user_id
             WHERE {$where}
             ORDER BY (r.status = 'pending') DESC,
                      CASE WHEN r.status = 'pending' THEN r.id END ASC,
                      r.id DESC
             LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function adminCount(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM module_activation_requests r WHERE ' . $where, $params)['c'] ?? 0);
    }

    public function pendingCount(): int
    {
        return (int) ($this->findOne("SELECT COUNT(*) AS c FROM module_activation_requests WHERE status = 'pending'")['c'] ?? 0);
    }

    /** @return array<int, array> module_id => pending request, for one organization (Super Admin's organization modules screen) */
    public function pendingForOrganization(int $organizationId): array
    {
        $rows = [];
        foreach ($this->findMany(
            "SELECT * FROM module_activation_requests WHERE organization_id = :org AND status = 'pending'",
            ['org' => $organizationId]
        ) as $row) {
            $rows[(int) $row['module_id']] = $row;
        }

        return $rows;
    }

    /** Runs $work in a transaction (joins the caller's, if one is open). */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    private static function pendingKey(int $organizationId, int $moduleId): string
    {
        return $organizationId . ':' . $moduleId;
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = '1 = 1';
        $params = [];
        if (in_array($filters['status'] ?? '', [self::PENDING, self::APPROVED, self::REJECTED], true)) {
            $where .= ' AND r.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['organization_id'])) {
            $where .= ' AND r.organization_id = :org';
            $params['org'] = (int) $filters['organization_id'];
        }

        return [$where, $params];
    }
}
