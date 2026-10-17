<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/** marketing_campaigns — every read/write scoped to the current organization. */
final class CampaignRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_campaigns';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM marketing_campaigns WHERE id = :id', ['id' => $id]);
    }

    /** @param array{q?: string, status?: string, channel?: string} $filters */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany(
            'SELECT * FROM marketing_campaigns WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM marketing_campaigns WHERE ' . $where, $params)['c'] ?? 0);
    }

    public function create(array $data): int
    {
        return $this->tenantInsertGetId($data);
    }

    public function updateCampaign(int $id, array $fields): int
    {
        return $fields === [] ? 0 : $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    /**
     * Atomic status transition: only changes the row when its status is
     * one of $from. Returns whether it changed (the caller "won").
     */
    public function transition(int $id, array $from, string $to, array $extra = []): bool
    {
        $in = [];
        $params = ['id' => $id];
        foreach (array_values($from) as $i => $status) {
            $in[] = ':from' . $i;
            $params['from' . $i] = $status;
        }

        return $this->tenantUpdate(array_merge(['status' => $to], $extra), 'id = :id AND status IN (' . implode(', ', $in) . ')', $params) > 0;
    }

    public function deleteDraft(int $id): int
    {
        return $this->tenantDelete("id = :id AND status = 'draft'", ['id' => $id]);
    }

    /**
     * Campaigns the worker should look at, across every organization —
     * the only unscoped read; each one is then processed inside its own
     * OrganizationContext.
     *
     * @return array<int, array{id: int, organization_id: int}>
     */
    public function activeAcrossOrganizations(int $limit = 50): array
    {
        return $this->findMany(
            "SELECT id, organization_id FROM marketing_campaigns WHERE status IN ('queued', 'sending') OR (status = 'preparing' AND prepare_cursor IS NOT NULL) ORDER BY id LIMIT " . max(1, $limit)
        );
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = 'organization_id = :org_id';
        $params = ['org_id' => OrganizationContext::id()];

        if (!empty($filters['q'])) {
            $where .= ' AND name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['channel'])) {
            $where .= ' AND channel = :channel';
            $params['channel'] = $filters['channel'];
        }

        return [$where, $params];
    }
}
