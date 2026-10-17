<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/** marketing_suppressions — tenant-scoped. */
final class SuppressionRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_suppressions';
    }

    /**
     * @param string[] $addresses normalized
     * @return array<string, string> address => reason, for the suppressed ones
     */
    public function reasonsFor(string $channel, array $addresses): array
    {
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            return [];
        }

        $params = ['org_id' => OrganizationContext::id(), 'channel' => $channel];
        $in = [];
        foreach ($addresses as $i => $address) {
            $in[] = ':a' . $i;
            $params['a' . $i] = $address;
        }

        $result = [];
        foreach ($this->findMany(
            'SELECT address, reason FROM marketing_suppressions WHERE organization_id = :org_id AND channel = :channel AND address IN (' . implode(', ', $in) . ')',
            $params
        ) as $row) {
            $result[(string) $row['address']] = (string) $row['reason'];
        }

        return $result;
    }

    /** Adds (or keeps) a suppression; an existing row keeps its first reason unless $override. */
    public function add(string $channel, string $address, string $reason, string $source, ?int $contactId, ?int $userId, bool $override = false): void
    {
        $sql = 'INSERT INTO marketing_suppressions (organization_id, channel, address, contact_id, reason, source, created_by_user_id)
                VALUES (:org_id, :channel, :address, :contact_id, :reason, :source, :user_id)';
        $sql .= $override ? ' ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source)' : ' ON DUPLICATE KEY UPDATE id = id';

        $this->query($sql, [
            'org_id' => OrganizationContext::id(),
            'channel' => $channel,
            'address' => $address,
            'contact_id' => $contactId,
            'reason' => $reason,
            'source' => $source,
            'user_id' => $userId,
        ]);
    }

    public function remove(int $id): int
    {
        return $this->tenantDelete('id = :id', ['id' => $id]);
    }

    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany('SELECT * FROM marketing_suppressions WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM marketing_suppressions WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = 'organization_id = :org_id';
        $params = ['org_id' => OrganizationContext::id()];
        if (!empty($filters['channel'])) {
            $where .= ' AND channel = :channel';
            $params['channel'] = $filters['channel'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND address LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        return [$where, $params];
    }
}
