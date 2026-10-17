<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Reads recipients from the existing CRM contacts (no second contact
 * store). Every query is pinned to OrganizationContext::id(); ids given
 * by a user are only ever used together with that condition, so another
 * organization's contacts can never be selected.
 *
 * Address per channel: SMS → contacts.phone (the synced main mobile),
 * else the first mobile in contact_phones; email → contacts.email, else
 * the primary row of contact_emails.
 */
final class AudienceRepository extends BaseRepository
{
    public const CHUNK = 500;

    private ?\Modules\Marketing\Segments\SegmentAudience $segments = null;

    /** Phase 2: lets the 'segment' audience type resolve saved segments. */
    public function setSegmentAudience(\Modules\Marketing\Segments\SegmentAudience $segments): void
    {
        $this->segments = $segments;
    }

    /** @param int[] $ids */
    public function countContactsInOrganization(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM contacts WHERE organization_id = :org_id AND id IN (' . implode(',', $ids) . ')',
            ['org_id' => OrganizationContext::id()]
        )['c'] ?? 0);
    }

    public function groupExists(int $id): bool
    {
        return $this->findOne('SELECT id FROM contact_categories WHERE id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]) !== null;
    }

    public function tagExists(int $id): bool
    {
        return $this->findOne('SELECT id FROM tags WHERE id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]) !== null;
    }

    /**
     * One chunk of matching contacts after $afterId (keyset paging, so
     * very large audiences are read in constant memory).
     *
     * @param int[] $ids for audience 'contacts'
     */
    public function chunk(string $channel, string $type, array $ids, int $afterId, ?array $snapshot = null): array
    {
        // Phone-text channels added by other modules (e.g. Bale) read the same columns as SMS.
        $alt = $channel === 'sms' || \Modules\Marketing\Channels\ChannelRegistry::phoneChannel($channel) !== null
            ? "(SELECT p.number FROM contact_phones p WHERE p.contact_id = c.id AND p.organization_id = c.organization_id AND p.type = 'mobile' ORDER BY p.is_primary DESC, p.id LIMIT 1)"
            : '(SELECT e.email FROM contact_emails e WHERE e.contact_id = c.id AND e.organization_id = c.organization_id ORDER BY e.is_primary DESC, e.id LIMIT 1)';

        $conditions = $this->conditions($type, $ids, $snapshot);
        if ($conditions === null) {
            return [];
        }
        [$where, $params] = $conditions;
        $where .= ' AND c.id > :after';
        $params['after'] = $afterId;

        return $this->findMany(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                    (SELECT name FROM companies WHERE companies.id = c.company_id) AS company_name,
                    ' . $alt . ' AS alt_address
             FROM contacts c WHERE ' . $where . ' ORDER BY c.id LIMIT ' . self::CHUNK,
            $params
        );
    }

    /** How many contacts the audience matches (one COUNT, no address checks) — decides sync vs background expansion. */
    public function countMatching(string $type, array $ids, ?array $snapshot = null): int
    {
        $conditions = $this->conditions($type, $ids, $snapshot);
        if ($conditions === null) {
            return 0;
        }
        [$where, $params] = $conditions;

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM contacts c WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** @return array{0: string, 1: array}|null WHERE clause on contacts c for an audience, always pinned to the organization */
    private function conditions(string $type, array $ids, ?array $snapshot = null): ?array
    {
        $params = ['org_id' => OrganizationContext::id()];
        $where = 'c.organization_id = :org_id';
        switch ($type) {
            case 'contacts':
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
                if ($ids === []) {
                    return null;
                }
                $where .= ' AND c.id IN (' . implode(',', $ids) . ')';
                break;
            case 'group':
                $where .= " AND c.status = 'active' AND c.category_id = :ref";
                $params['ref'] = (int) ($ids[0] ?? 0);
                break;
            case 'tag':
                $where .= " AND c.status = 'active' AND c.id IN (SELECT tg.taggable_id FROM taggables tg JOIN tags t ON t.id = tg.tag_id
                            WHERE tg.taggable_type = 'contact' AND tg.tag_id = :ref AND t.organization_id = :tag_org)";
                $params['ref'] = (int) ($ids[0] ?? 0);
                $params['tag_org'] = OrganizationContext::id();
                break;
            case 'all':
                $where .= " AND c.status = 'active'";
                break;
            case 'segment':
                // Phase 2: a saved segment (static members or dynamic rules), always inside this organization.
                $predicate = $this->segments?->predicate((int) ($ids[0] ?? 0), $snapshot);
                if ($predicate === null) {
                    return null;
                }
                $where .= ' AND ' . $predicate[0];
                $params += $predicate[1];
                break;
            default:
                return null;
        }

        return [$where, $params];
    }

    /** Contact fields used for personalization, for one contact of the organization. */
    public function contactForPersonalization(int $contactId): ?array
    {
        return $this->findOne(
            'SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                    (SELECT name FROM companies WHERE companies.id = c.company_id) AS company_name
             FROM contacts c WHERE c.id = :id AND c.organization_id = :org_id',
            ['id' => $contactId, 'org_id' => OrganizationContext::id()]
        );
    }

    /** Picker options (newest first, capped). */
    public function contactOptions(int $limit = 2000): array
    {
        return $this->findMany(
            'SELECT id, first_name, last_name, phone, email FROM contacts WHERE organization_id = :org_id ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['org_id' => OrganizationContext::id()]
        );
    }

    public function groups(): array
    {
        return $this->findMany('SELECT id, name FROM contact_categories WHERE organization_id = :org_id ORDER BY name', ['org_id' => OrganizationContext::id()]);
    }

    public function tags(): array
    {
        return $this->findMany('SELECT id, name FROM tags WHERE organization_id = :org_id ORDER BY name', ['org_id' => OrganizationContext::id()]);
    }

    public function organizationName(): string
    {
        return (string) ($this->findOne('SELECT name FROM organizations WHERE id = :id', ['id' => OrganizationContext::id()])['name'] ?? '');
    }

    public function organizationLanguage(): string
    {
        return (string) ($this->findOne('SELECT default_language FROM organizations WHERE id = :id', ['id' => OrganizationContext::id()])['default_language'] ?? 'en');
    }
}
