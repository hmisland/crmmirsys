<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * contact_import_batches + contact_import_batch_items: the record of
 * what each CSV import created/updated, so the latest one can be
 * rolled back. Both tables carry organization_id; items are only ever
 * reached through a batch that was already org-scoped.
 */
final class ContactImportBatchRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_import_batches';
    }

    public function create(int $userId, string $fileName): int
    {
        return $this->tenantInsertGetId(['user_id' => $userId, 'file_name' => mb_substr($fileName, 0, 255)]);
    }

    /** The organization's most recent import, whatever its status. */
    public function latest(): ?array
    {
        // Org condition written out (not tenantFindOne): the user-name
        // subquery has its own WHERE, which the automatic injection
        // would scope instead of the outer query.
        try {
            return $this->findOne(
                "SELECT b.*, (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = b.user_id) AS user_name
                 FROM contact_import_batches b WHERE b.organization_id = :org_id ORDER BY b.id DESC LIMIT 1",
                ['org_id' => OrganizationContext::id()]
            );
        } catch (\PDOException) {
            return null; // table not migrated yet
        }
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM contact_import_batches WHERE id = :id', ['id' => $id]);
    }

    public function updateBatch(int $id, array $fields): void
    {
        $this->tenantUpdate($fields, 'id = :id', ['id' => $id]);
    }

    public function addItem(int $batchId, string $entityType, int $entityId, string $action, ?array $snapshot = null, ?string $stamp = null): void
    {
        $columns = [
            'batch_id' => $batchId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'snapshot' => $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
            'stamp' => $stamp,
        ];

        if (!SuperAdminContext::isActive()) {
            $columns['organization_id'] = OrganizationContext::id();
        }

        $this->insertGetId('contact_import_batch_items', $columns);
    }

    public function setItemStamp(int $batchId, string $entityType, int $entityId, string $stamp): void
    {
        $this->update(
            'contact_import_batch_items',
            ['stamp' => $stamp],
            'batch_id = :batch_id AND entity_type = :entity_type AND entity_id = :entity_id',
            ['batch_id' => $batchId, 'entity_type' => $entityType, 'entity_id' => $entityId]
        );
    }

    public function items(int $batchId): array
    {
        $rows = $this->findMany(
            'SELECT * FROM contact_import_batch_items WHERE batch_id = :batch_id ORDER BY id',
            ['batch_id' => $batchId]
        );

        foreach ($rows as &$row) {
            $row['snapshot'] = $row['snapshot'] !== null ? json_decode((string) $row['snapshot'], true) : null;
        }

        return $rows;
    }

    /** contacts.updated_at, read straight from the row — the rollback's "edited since?" check. */
    public function contactUpdatedAt(int $contactId): ?string
    {
        $row = $this->findOne(
            'SELECT updated_at FROM contacts WHERE id = :id AND organization_id = :org_id',
            ['id' => $contactId, 'org_id' => OrganizationContext::id()]
        );

        return $row['updated_at'] ?? null;
    }
}
