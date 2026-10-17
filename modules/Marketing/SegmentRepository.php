<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * marketing_segments + marketing_segment_contacts. Every query carries the
 * current organization; deleted (soft) segments are invisible except to
 * findIncludingDeleted(), used for campaigns that already reference one.
 */
final class SegmentRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_segments';
    }

    public function find(int $id): ?array
    {
        return $this->findOne(
            'SELECT * FROM marketing_segments WHERE id = :id AND organization_id = :org_id AND deleted_at IS NULL',
            ['id' => $id, 'org_id' => OrganizationContext::id()]
        );
    }

    public function findIncludingDeleted(int $id): ?array
    {
        return $this->findOne('SELECT * FROM marketing_segments WHERE id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]);
    }

    /** @param array{q?: string, type?: string} $filters */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany('SELECT * FROM marketing_segments WHERE ' . $where . ' ORDER BY name, id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM marketing_segments WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** For pickers: every live segment of the organization (id, name, type). */
    public function options(): array
    {
        return $this->findMany(
            'SELECT id, name, type FROM marketing_segments WHERE organization_id = :org_id AND deleted_at IS NULL ORDER BY name',
            ['org_id' => OrganizationContext::id()]
        );
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateSegment(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function softDelete(int $id, int $actorId): int
    {
        return $this->tenantUpdate(['deleted_at' => date('Y-m-d H:i:s'), 'updated_by_user_id' => $actorId], 'id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /**
     * The database clock — static memberships are timestamped by MySQL
     * (DEFAULT CURRENT_TIMESTAMP), so a cutoff compared with them must come
     * from the same clock, not PHP's (the time zones can differ).
     */
    public function databaseNow(): string
    {
        return (string) ($this->findOne('SELECT NOW() AS now')['now'] ?? date('Y-m-d H:i:s'));
    }

    /** Highest contact id of the organization now — contacts created later have higher ids. */
    public function maxContactId(): int
    {
        return (int) ($this->findOne('SELECT MAX(id) AS m FROM contacts WHERE organization_id = :org_id', ['org_id' => OrganizationContext::id()])['m'] ?? 0);
    }

    // --- Static members ------------------------------------------------------

    /** Member counts for several static segments in ONE query. @return array<int, int> */
    public function memberCounts(array $segmentIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $segmentIds), fn ($i) => $i > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->findMany(
            'SELECT sc.segment_id, COUNT(*) AS c FROM marketing_segment_contacts sc
             WHERE sc.organization_id = :org_id AND sc.segment_id IN (' . implode(',', $ids) . ') GROUP BY sc.segment_id',
            ['org_id' => OrganizationContext::id()]
        );

        return array_map('intval', array_column($rows, 'c', 'segment_id'));
    }

    /**
     * Adds contacts that belong to this organization (others are ignored
     * by the JOIN). Returns how many were added.
     *
     * @param int[] $contactIds
     */
    public function addMembers(int $segmentId, array $contactIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }

        $added = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $added += $this->query(
                'INSERT IGNORE INTO marketing_segment_contacts (segment_id, contact_id, organization_id)
                 SELECT :segment_id, c.id, c.organization_id FROM contacts c
                 WHERE c.organization_id = :org_id AND c.id IN (' . implode(',', $chunk) . ')',
                ['segment_id' => $segmentId, 'org_id' => OrganizationContext::id()]
            )->rowCount();
        }

        return $added;
    }

    /** @param int[] $contactIds */
    public function removeMembers(int $segmentId, array $contactIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), fn ($i) => $i > 0)));
        if ($ids === []) {
            return 0;
        }

        return $this->delete(
            'marketing_segment_contacts',
            'segment_id = :segment_id AND organization_id = :org_id AND contact_id IN (' . implode(',', $ids) . ')',
            ['segment_id' => $segmentId, 'org_id' => OrganizationContext::id()]
        );
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = 'organization_id = :org_id AND deleted_at IS NULL';
        $params = ['org_id' => OrganizationContext::id()];
        if (!empty($filters['q'])) {
            $where .= ' AND name LIKE :q';
            $params['q'] = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
        }
        if (in_array($filters['type'] ?? '', ['static', 'dynamic'], true)) {
            $where .= ' AND type = :type';
            $params['type'] = $filters['type'];
        }

        return [$where, $params];
    }
}
