<?php

declare(strict_types=1);

namespace Modules\CustomerPortal;

use App\Infrastructure\Repositories\BaseRepository;

/**
 * Sign-in lookups and one-time codes of the Customer Portal.
 *
 * Deliberately NOT tenant-scoped through OrganizationContext: a customer
 * request has no staff user, and the portal must not depend on whatever
 * context a staff session in the same browser set. Every query names the
 * organization explicitly; contact lookups return only ACTIVE contacts.
 */
final class CustomerPortalRepository extends BaseRepository
{
    /** An active organization by its public slug (the login address). */
    public function organizationBySlug(string $slug): ?array
    {
        return $this->findOne("SELECT id, name, slug, default_language FROM organizations WHERE slug = :slug AND status = 'active'", ['slug' => $slug]);
    }

    public function organization(int $organizationId): ?array
    {
        return $this->findOne("SELECT id, name, slug, default_language FROM organizations WHERE id = :id AND status = 'active'", ['id' => $organizationId]);
    }

    /** @return int[] active contacts of the organization with this email (main or additional), case-insensitive */
    public function contactIdsByEmail(int $organizationId, string $email): array
    {
        return $this->ids(
            "SELECT c.id FROM contacts c WHERE c.organization_id = :org AND c.status = 'active' AND LOWER(TRIM(c.email)) = :email
             UNION
             SELECT c.id FROM contact_emails e JOIN contacts c ON c.id = e.contact_id AND c.organization_id = e.organization_id
             WHERE e.organization_id = :org2 AND c.status = 'active' AND LOWER(TRIM(e.email)) = :email2",
            ['org' => $organizationId, 'org2' => $organizationId, 'email' => $email, 'email2' => $email]
        );
    }

    /**
     * @param string[] $variants the ways one mobile may have been typed in (0912…, 912…, 98…, +98…, 0098…)
     * @return int[] active contacts of the organization with this mobile (main phone or an additional mobile)
     */
    public function contactIdsByMobile(int $organizationId, array $variants): array
    {
        [$in, $params] = $this->inList($variants, 'm');
        [$in2, $params2] = $this->inList($variants, 'n');

        return $this->ids(
            "SELECT c.id FROM contacts c WHERE c.organization_id = :org AND c.status = 'active'
                AND REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '.', '') IN ({$in})
             UNION
             SELECT c.id FROM contact_phones p JOIN contacts c ON c.id = p.contact_id AND c.organization_id = p.organization_id
             WHERE p.organization_id = :org2 AND c.status = 'active' AND p.type = 'mobile'
                AND REPLACE(REPLACE(REPLACE(p.number, ' ', ''), '-', ''), '.', '') IN ({$in2})",
            ['org' => $organizationId, 'org2' => $organizationId] + $params + $params2
        );
    }

    /**
     * @param string[] $variants the national ID in Latin, Persian and Arabic digits
     * @return int[] active contacts of the organization with this national ID
     */
    public function contactIdsByNationalId(int $organizationId, array $variants): array
    {
        [$in, $params] = $this->inList($variants, 'n');

        return $this->ids(
            "SELECT c.id FROM contacts c WHERE c.organization_id = :org AND c.status = 'active'
                AND REPLACE(REPLACE(REPLACE(c.national_id, ' ', ''), '-', ''), '.', '') IN ({$in})",
            ['org' => $organizationId] + $params
        );
    }

    /** One active contact of the organization (null otherwise). */
    public function activeContact(int $organizationId, int $contactId): ?array
    {
        return $this->findOne("SELECT * FROM contacts WHERE id = :id AND organization_id = :org AND status = 'active'", ['id' => $contactId, 'org' => $organizationId]);
    }

    /** @return string[] the contact's mobiles: the main phone first, then additional mobiles */
    public function contactMobiles(int $organizationId, int $contactId): array
    {
        $rows = $this->findMany(
            "SELECT c.phone AS number, 0 AS pos FROM contacts c WHERE c.id = :id AND c.organization_id = :org AND c.phone IS NOT NULL
             UNION ALL
             SELECT p.number, 1 + p.id AS pos FROM contact_phones p WHERE p.contact_id = :id2 AND p.organization_id = :org2 AND p.type = 'mobile'
             ORDER BY pos",
            ['id' => $contactId, 'org' => $organizationId, 'id2' => $contactId, 'org2' => $organizationId]
        );

        return array_values(array_unique(array_column($rows, 'number')));
    }

    // --- One-time codes -------------------------------------------------------------

    public function createOtp(array $row): int
    {
        return $this->insertGetId('customer_portal_otps', $row);
    }

    public function markDelivered(int $id): void
    {
        $this->update('customer_portal_otps', ['delivered' => 1], 'id = :id', ['id' => $id]);
    }

    /** Supersedes every still-usable code of the contact (only the newest code works). */
    public function invalidateActive(int $organizationId, int $contactId): void
    {
        $this->update(
            'customer_portal_otps',
            ['invalidated_at' => date('Y-m-d H:i:s')],
            'organization_id = :org AND contact_id = :contact AND used_at IS NULL AND invalidated_at IS NULL',
            ['org' => $organizationId, 'contact' => $contactId]
        );
    }

    public function lastOtpAt(int $organizationId, int $contactId): ?string
    {
        $row = $this->findOne('SELECT MAX(created_at) AS t FROM customer_portal_otps WHERE organization_id = :org AND contact_id = :contact', ['org' => $organizationId, 'contact' => $contactId]);

        return $row['t'] ?? null;
    }

    public function otpsSince(int $organizationId, int $contactId, string $since): int
    {
        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM customer_portal_otps WHERE organization_id = :org AND contact_id = :contact AND created_at >= :since',
            ['org' => $organizationId, 'contact' => $contactId, 'since' => $since]
        )['c'] ?? 0);
    }

    public function otp(int $id, int $organizationId): ?array
    {
        return $this->findOne('SELECT * FROM customer_portal_otps WHERE id = :id AND organization_id = :org', ['id' => $id, 'org' => $organizationId]);
    }

    /** Counts one verification attempt; false once the code is used, superseded or out of attempts. */
    public function countAttempt(int $id): bool
    {
        return $this->query(
            'UPDATE customer_portal_otps SET attempts = attempts + 1
             WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL AND attempts < max_attempts',
            ['id' => $id]
        )->rowCount() === 1;
    }

    /** Marks the code used — once: a replayed or concurrent second use gets false. */
    public function markUsed(int $id): bool
    {
        return $this->query(
            'UPDATE customer_portal_otps SET used_at = :now WHERE id = :id AND used_at IS NULL AND invalidated_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'id' => $id]
        )->rowCount() === 1;
    }

    // --- Internals ------------------------------------------------------------------

    /** @return int[] */
    private function ids(string $sql, array $params): array
    {
        return array_values(array_unique(array_map('intval', array_column($this->findMany($sql, $params), 'id'))));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function inList(array $values, string $prefix): array
    {
        $values = array_values(array_unique(array_filter(array_map('strval', $values), fn ($v) => $v !== '')));
        if ($values === []) {
            $values = ["\0"]; // matches nothing
        }
        $params = [];
        foreach ($values as $i => $value) {
            $params[$prefix . $i] = $value;
        }

        return [implode(', ', array_map(fn ($k) => ':' . $k, array_keys($params))), $params];
    }
}
