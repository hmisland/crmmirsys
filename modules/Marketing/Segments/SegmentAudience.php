<?php

declare(strict_types=1);

namespace Modules\Marketing\Segments;

use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\SegmentRepository;

/**
 * Turns a segment into a WHERE predicate on contacts "c" for the existing
 * campaign audience path (AudienceRepository → AudienceResolver →
 * approval / worker). There is no second recipient-expansion system.
 *
 * Live (no snapshot): the segment as it is now — used for preview,
 * review and the approval re-check.
 * Snapshot (stored on the campaign at approval): dynamic = the rules that
 * were approved, evaluated as of the approval time ("last N days" does not
 * slide) and over contacts that existed then (id <= max_contact_id);
 * static = members that existed at approval time. Later edits or deletion
 * of the segment, and contacts created later, don't change a campaign that
 * is already being prepared in the background.
 */
final class SegmentAudience
{
    public function __construct(
        private readonly SegmentRepository $segments,
        private readonly SegmentRules $rules,
    ) {
    }

    /** @return array{0: string, 1: array}|null null = no such live segment */
    public function predicate(int $segmentId, ?array $snapshot = null): ?array
    {
        $params = new SqlParams(OrganizationContext::id(), 'sga');

        if ($snapshot !== null && (int) ($snapshot['segment_id'] ?? 0) === $segmentId) {
            if (($snapshot['type'] ?? '') === 'dynamic') {
                $params = new SqlParams(OrganizationContext::id(), 'sga', isset($snapshot['as_of']) ? (string) $snapshot['as_of'] : null);
                $sql = $this->rules->compile((array) ($snapshot['rules'] ?? []), $params);
                if (isset($snapshot['max_contact_id'])) {
                    $sql = '(' . $sql . ' AND c.id <= ' . $params->bind((int) $snapshot['max_contact_id']) . ')';
                }

                return [$sql, $params->all()];
            }
            if (($snapshot['type'] ?? '') === 'static') {
                return [$this->members($segmentId, $params, (string) ($snapshot['cutoff'] ?? '')), $params->all()];
            }
        }

        $segment = $this->segments->find($segmentId);
        if ($segment === null) {
            return null;
        }

        if ($segment['type'] === 'dynamic') {
            return [$this->rules->compile((array) json_decode((string) $segment['rules'], true), $params), $params->all()];
        }

        return [$this->members($segmentId, $params, null), $params->all()];
    }

    /** What a campaign stores at approval. */
    public function snapshot(int $segmentId): ?array
    {
        $segment = $this->segments->find($segmentId);
        if ($segment === null) {
            return null;
        }

        return $segment['type'] === 'dynamic'
            ? ['segment_id' => $segmentId, 'type' => 'dynamic', 'rules' => json_decode((string) $segment['rules'], true),
               'as_of' => $this->segments->databaseNow(), 'max_contact_id' => $this->segments->maxContactId()]
            : ['segment_id' => $segmentId, 'type' => 'static', 'cutoff' => $this->segments->databaseNow()];
    }

    private function members(int $segmentId, SqlParams $params, ?string $cutoff): string
    {
        $sql = 'c.id IN (SELECT sc.contact_id FROM marketing_segment_contacts sc WHERE sc.segment_id = ' . $params->bind($segmentId)
            . ' AND sc.organization_id = ' . $params->org();
        if ($cutoff !== null && $cutoff !== '') {
            $sql .= ' AND sc.created_at <= ' . $params->bind($cutoff);
        }

        return $sql . ')';
    }
}
