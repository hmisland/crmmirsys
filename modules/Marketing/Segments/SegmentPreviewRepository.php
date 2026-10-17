<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Counting and paging matching contacts for segment previews and member
 * search: one COUNT and one LIMIT/OFFSET query each (no per-contact
 * queries). Always pinned to the current organization.
 */
final class SegmentPreviewRepository extends BaseRepository
{
    private const COLUMNS = 'c.id, c.first_name, c.last_name, c.phone, c.email, c.city, c.status';

    public function __construct(private readonly SegmentRules $rules, private readonly SegmentAudience $audience)
    {
    }

    public function countRules(array $rules): int
    {
        [$where, $params] = $this->rulesWhere($rules);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM contacts c WHERE ' . $where, $params)['c'] ?? 0);
    }

    public function rowsRules(array $rules, int $limit, int $offset): array
    {
        [$where, $params] = $this->rulesWhere($rules);

        return $this->findMany('SELECT ' . self::COLUMNS . ' FROM contacts c WHERE ' . $where . ' ORDER BY c.id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
    }

    public function countSegment(int $segmentId): int
    {
        $where = $this->segmentWhere($segmentId);

        return $where === null ? 0 : (int) ($this->findOne('SELECT COUNT(*) AS c FROM contacts c WHERE ' . $where[0], $where[1])['c'] ?? 0);
    }

    public function rowsSegment(int $segmentId, int $limit, int $offset): array
    {
        $where = $this->segmentWhere($segmentId);

        return $where === null ? [] : $this->findMany('SELECT ' . self::COLUMNS . ' FROM contacts c WHERE ' . $where[0] . ' ORDER BY c.id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $where[1]);
    }

    /** Contacts matching a search, with whether each is already in the segment. */
    public function searchContacts(string $q, int $segmentId, int $limit, int $offset): array
    {
        [$where, $params] = $this->searchWhere($q);
        $params['seg_id'] = $segmentId;

        return $this->findMany(
            'SELECT ' . self::COLUMNS . ', (sc.contact_id IS NOT NULL) AS in_segment FROM contacts c
             LEFT JOIN marketing_segment_contacts sc ON sc.contact_id = c.id AND sc.segment_id = :seg_id
             WHERE ' . $where . ' ORDER BY c.id LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function countSearchContacts(string $q): int
    {
        [$where, $params] = $this->searchWhere($q);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM contacts c WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** @return array{0: string, 1: array} */
    private function rulesWhere(array $rules): array
    {
        $params = new SqlParams(OrganizationContext::id(), 'pv');
        $sql = $this->rules->compile($rules, $params);

        return ['c.organization_id = :org_id AND ' . $sql, ['org_id' => OrganizationContext::id()] + $params->all()];
    }

    /** @return array{0: string, 1: array}|null */
    private function segmentWhere(int $segmentId): ?array
    {
        $predicate = $this->audience->predicate($segmentId);

        return $predicate === null ? null : ['c.organization_id = :org_id AND ' . $predicate[0], ['org_id' => OrganizationContext::id()] + $predicate[1]];
    }

    /** @return array{0: string, 1: array} */
    private function searchWhere(string $q): array
    {
        $where = 'c.organization_id = :org_id';
        $params = ['org_id' => OrganizationContext::id()];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $where .= " AND (c.first_name LIKE :q1 OR c.last_name LIKE :q2 OR c.phone LIKE :q3 OR c.email LIKE :q4 OR CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')) LIKE :q5)";
            foreach ([1, 2, 3, 4, 5] as $n) {
                $params['q' . $n] = $like;
            }
        }

        return [$where, $params];
    }
}
