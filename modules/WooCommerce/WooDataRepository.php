<?php

declare(strict_types=1);

namespace Modules\WooCommerce;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * woo_customers / woo_orders / woo_order_items / woo_products
 * (WooCommerce migration 0001). Every statement carries the current
 * organization_id explicitly (the worker runs each job inside the job's
 * own organization) plus the connection id, so one store's data can
 * never be written into or read from another organization.
 * Upserts rely on the unique (connection, external id) keys.
 */
final class WooDataRepository extends BaseRepository
{
    /** Customer columns an import writes (billing data of the latest order). */
    public const CUSTOMER_FIELDS = ['email', 'first_name', 'last_name', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    /**
     * Inserts or updates one customer. A changed customer becomes pending
     * for the contact sync again (contact_sync_status NULL); an existing
     * contact link is never replaced, only a missing one filled.
     *
     * @return int local woo_customers.id
     */
    public function upsertCustomer(int $connectionId, string $externalKey, ?int $externalId, array $fields, ?string $modifiedAt): int
    {
        $existing = $this->findOne(
            'SELECT * FROM woo_customers WHERE organization_id = :org AND connection_id = :conn AND external_key = :k',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId, 'k' => $externalKey]
        );
        $columns = [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            $columns[$field] = $fields[$field] ?? null;
        }
        $contactId = $fields['contact_id'] ?? null;
        if ($existing === null) {
            $insert = ['organization_id' => OrganizationContext::id(), 'connection_id' => $connectionId, 'external_key' => $externalKey, 'external_id' => $externalId]
                + $columns + ['contact_id' => $contactId, 'external_modified_at' => $modifiedAt];
            $names = array_keys($insert);
            $this->query(
                'INSERT INTO woo_customers (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                $insert
            );

            return (int) $this->pdo()->lastInsertId();
        }

        $link = $existing['contact_id'] === null && $contactId !== null ? ['contact_id' => $contactId] : [];
        // Never let an older payload overwrite newer data; still fill a missing contact link.
        if ($modifiedAt !== null && $existing['external_modified_at'] !== null && $modifiedAt < $existing['external_modified_at']) {
            if ($link !== []) {
                $this->update('woo_customers', $link, 'id = :id AND organization_id = :org', ['id' => $existing['id'], 'org' => OrganizationContext::id()]);
            }

            return (int) $existing['id'];
        }
        $columns = array_filter($columns, fn ($v) => $v !== null && $v !== '');
        $changed = array_filter($columns, fn ($v, $k) => (string) ($existing[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH) !== [];
        $update = $columns + $link + ($modifiedAt !== null ? ['external_modified_at' => $modifiedAt] : []);
        if ($changed) {
            $update += ['contact_sync_status' => null, 'contact_sync_note' => null];
        }
        if ($update !== []) {
            $this->update('woo_customers', $update, 'id = :id AND organization_id = :org', ['id' => $existing['id'], 'org' => OrganizationContext::id()]);
        }

        return (int) $existing['id'];
    }

    public function findOrderForUpdate(int $connectionId, int $externalId): ?array
    {
        return $this->findOne(
            'SELECT id, status, external_modified_at FROM woo_orders WHERE organization_id = :org AND connection_id = :conn AND external_id = :ext FOR UPDATE',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId, 'ext' => $externalId]
        );
    }

    public function insertOrder(int $connectionId, int $externalId, array $fields): int
    {
        return $this->insertGetId('woo_orders', ['organization_id' => OrganizationContext::id(), 'connection_id' => $connectionId, 'external_id' => $externalId] + $fields);
    }

    public function updateOrder(int $id, array $fields): void
    {
        $this->update('woo_orders', $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    /** Replaces an order's line items (the payload always carries the full list). */
    public function replaceItems(int $orderId, array $items): void
    {
        $org = OrganizationContext::id();
        $this->query('DELETE FROM woo_order_items WHERE order_id = :o AND organization_id = :org', ['o' => $orderId, 'org' => $org]);
        foreach ($items as $item) {
            $this->insertGetId('woo_order_items', ['organization_id' => $org, 'order_id' => $orderId] + $item);
        }
    }

    public function upsertProduct(int $connectionId, int $externalId, string $name, ?string $sku): int
    {
        $this->query(
            'INSERT INTO woo_products (organization_id, connection_id, external_id, name, sku) VALUES (:org, :conn, :ext, :name, :sku)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name), sku = VALUES(sku)',
            ['org' => OrganizationContext::id(), 'conn' => $connectionId, 'ext' => $externalId, 'name' => $name, 'sku' => $sku]
        );

        return (int) $this->pdo()->lastInsertId();
    }

    /** One order + its items atomically (joins an outer transaction when one is open). */
    public function atomic(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    /** Settings page / tests: row counts for one connection. */
    public function counts(int $connectionId): array
    {
        $params = ['org' => OrganizationContext::id(), 'conn' => $connectionId];
        $one = fn (string $table) => (int) ($this->findOne("SELECT COUNT(*) AS c FROM {$table} WHERE organization_id = :org AND connection_id = :conn", $params)['c'] ?? 0);

        return ['customers' => $one('woo_customers'), 'orders' => $one('woo_orders'), 'products' => $one('woo_products')];
    }
}
