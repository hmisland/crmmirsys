<?php

declare(strict_types=1);

namespace Modules\WooCommerce\ContactSync;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * Storage of the customer → contact sync: per-shop settings
 * (woo_contact_sync_settings), the per-customer sync state on
 * woo_customers, and the organization-scoped contact lookups by email /
 * mobile. Every statement carries the current organization_id
 * explicitly; the only cross-organization read is enabledConnections()
 * for the cron task, which then works inside each row's organization.
 */
final class ContactSyncRepository extends BaseRepository
{
    private const STALE_MINUTES = 10;

    // --- Settings --------------------------------------------------------------

    public function settings(int $connectionId): ?array
    {
        return $this->findOne(
            'SELECT * FROM woo_contact_sync_settings WHERE organization_id = :org AND connection_id = :conn',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        );
    }

    public function saveSettings(int $connectionId, array $fields): void
    {
        if ($this->settings($connectionId) === null) {
            $this->insertGetId('woo_contact_sync_settings', ['organization_id' => OrganizationContext::id(), 'connection_id' => $connectionId] + $fields);

            return;
        }
        $this->update('woo_contact_sync_settings', $fields, 'organization_id = :org AND connection_id = :conn', ['org' => OrganizationContext::id(), 'conn' => $connectionId]);
    }

    /** @return array<int, array{organization_id: int, connection_id: int}> enabled syncs of enabled WooCommerce connections */
    public function enabledConnections(int $limit = 100): array
    {
        return $this->findMany(
            "SELECT s.organization_id, s.connection_id FROM woo_contact_sync_settings s
             JOIN integration_connections c ON c.id = s.connection_id AND c.organization_id = s.organization_id
             WHERE s.enabled = 1 AND c.enabled = 1 AND c.provider = 'woocommerce' ORDER BY s.connection_id LIMIT " . max(1, $limit)
        );
    }

    // --- Customers -------------------------------------------------------------

    public function customer(int $customerId): ?array
    {
        return $this->findOne('SELECT * FROM woo_customers WHERE id = :id AND organization_id = :org', ['id' => $customerId, 'org' => OrganizationContext::id()]);
    }

    /** @return int[] pending customers of one shop (new or changed since the last run), oldest first */
    public function pendingIds(int $connectionId, int $limit): array
    {
        return array_map('intval', array_column($this->findMany(
            'SELECT id FROM woo_customers WHERE organization_id = :org AND connection_id = :conn AND contact_sync_status IS NULL ORDER BY id LIMIT ' . max(1, $limit),
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        ), 'id'));
    }

    /** Claims a pending customer; false when another run has it. */
    public function claim(int $customerId): bool
    {
        return $this->query(
            "UPDATE woo_customers SET contact_sync_status = 'processing' WHERE id = :id AND organization_id = :org AND contact_sync_status IS NULL",
            ['id' => $customerId, 'org' => OrganizationContext::id()]
        )->rowCount() > 0;
    }

    /** Writes the outcome of a claimed customer (only while still claimed — a change in between keeps it pending). */
    public function finish(int $customerId, string $status, ?string $note, ?int $contactId): void
    {
        $this->query(
            "UPDATE woo_customers SET contact_sync_status = :status, contact_sync_note = :note, contact_synced_at = :at,
                contact_id = COALESCE(:contact, contact_id)
             WHERE id = :id AND organization_id = :org AND contact_sync_status = 'processing'",
            ['status' => $status, 'note' => $note, 'at' => date('Y-m-d H:i:s'), 'contact' => $contactId, 'id' => $customerId, 'org' => OrganizationContext::id()]
        );
    }

    /** Every customer of the shop back to pending (after the settings changed). */
    public function markAllPending(int $connectionId): void
    {
        $this->query(
            'UPDATE woo_customers SET contact_sync_status = NULL, contact_sync_note = NULL WHERE organization_id = :org AND connection_id = :conn',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        );
    }

    /** Claims left by a run that died go back to pending (database clock). */
    public function releaseStale(int $connectionId): void
    {
        $this->query(
            "UPDATE woo_customers SET contact_sync_status = NULL WHERE organization_id = :org AND connection_id = :conn
             AND contact_sync_status = 'processing' AND updated_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        );
    }

    /** @return array<string, int> status => customers ("pending" for NULL) */
    public function statusCounts(int $connectionId): array
    {
        $counts = [];
        foreach ($this->findMany(
            'SELECT COALESCE(contact_sync_status, \'pending\') AS s, COUNT(*) AS c FROM woo_customers WHERE organization_id = :org AND connection_id = :conn GROUP BY s',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        ) as $row) {
            $counts[(string) $row['s']] = (int) $row['c'];
        }

        return $counts;
    }

    /** Customers needing attention (skipped or conflict), newest first. */
    public function problems(int $connectionId, int $limit = 20): array
    {
        return $this->findMany(
            "SELECT id, first_name, last_name, email, phone, contact_sync_status, contact_sync_note, contact_synced_at FROM woo_customers
             WHERE organization_id = :org AND connection_id = :conn AND contact_sync_status IN ('skipped', 'conflict')
             ORDER BY contact_synced_at DESC, id DESC LIMIT " . max(1, $limit),
            ['org' => OrganizationContext::id(), 'conn' => $connectionId]
        );
    }

    // --- Contact lookups (current organization only) --------------------------------

    /** @return int[] contacts with this email (main or additional), at most 3 */
    public function contactIdsByEmail(string $email): array
    {
        return array_map('intval', array_column($this->findMany(
            'SELECT id FROM contacts WHERE organization_id = :org1 AND email = :e1
             UNION SELECT contact_id AS id FROM contact_emails WHERE organization_id = :org2 AND email = :e2 LIMIT 3',
            ['org1' => OrganizationContext::id(), 'org2' => OrganizationContext::id(), 'e1' => $email, 'e2' => $email]
        ), 'id'));
    }

    /**
     * @param string[] $numbers stored forms of one number
     * @return int[] contacts with one of these numbers (main or additional), at most 3
     */
    public function contactIdsByPhone(array $numbers): array
    {
        $params = ['org1' => OrganizationContext::id(), 'org2' => OrganizationContext::id()];
        $a = [];
        $b = [];
        foreach (array_values(array_unique($numbers)) as $i => $number) {
            $a[] = ':a' . $i;
            $b[] = ':b' . $i;
            $params['a' . $i] = $params['b' . $i] = $number;
        }
        if ($a === []) {
            return [];
        }

        return array_map('intval', array_column($this->findMany(
            'SELECT id FROM contacts WHERE organization_id = :org1 AND phone IN (' . implode(', ', $a) . ')
             UNION SELECT contact_id AS id FROM contact_phones WHERE organization_id = :org2 AND number IN (' . implode(', ', $b) . ') LIMIT 3',
            $params
        ), 'id'));
    }

    /** @return array{mobile: bool, landline: bool, emails: string[]} what the contact already has */
    public function contactChannels(int $contactId): array
    {
        $params = ['org' => OrganizationContext::id(), 'id' => $contactId];
        $types = array_column($this->findMany('SELECT DISTINCT type FROM contact_phones WHERE organization_id = :org AND contact_id = :id', $params), 'type');
        $emails = array_map('strtolower', array_column($this->findMany('SELECT email FROM contact_emails WHERE organization_id = :org AND contact_id = :id', $params), 'email'));

        return ['mobile' => in_array('mobile', $types, true), 'landline' => in_array('landline', $types, true), 'emails' => $emails];
    }

    /** Runs $work in a transaction (joins the caller's, if one is open). */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    // --- Orders panel ------------------------------------------------------------

    /** The contact's shop orders (every connected shop of the organization), newest first. */
    public function ordersForContact(int $contactId, int $limit = 20): array
    {
        return $this->findMany(
            'SELECT o.id, o.order_number, o.status, o.total, o.currency, o.external_created_at, c.name AS shop_name
             FROM woo_orders o
             JOIN woo_customers wc ON wc.id = o.customer_id AND wc.organization_id = o.organization_id
             JOIN integration_connections c ON c.id = o.connection_id AND c.organization_id = o.organization_id
             WHERE o.organization_id = :org AND wc.contact_id = :contact
             ORDER BY o.external_created_at DESC, o.id DESC LIMIT ' . max(1, $limit),
            ['org' => OrganizationContext::id(), 'contact' => $contactId]
        );
    }
}
