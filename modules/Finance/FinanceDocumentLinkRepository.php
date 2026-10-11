<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * finance_document_links (module migration 0016) — tenant-scoped like every
 * other table: every read and write is confined to the caller's
 * organization by TenantScopedRepository.
 */
final class FinanceDocumentLinkRepository extends TenantScopedRepository
{
    public const DOCUMENT_TYPES = ['proforma', 'invoice'];

    protected function table(): string
    {
        return 'finance_document_links';
    }

    /** The one link of a document for $linkedType (a document belongs to at most one record per type). */
    public function forDocument(string $documentType, int $documentId, string $linkedType): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM finance_document_links WHERE document_type = :doc_type AND document_id = :doc_id AND linked_type = :type',
            ['doc_type' => $documentType, 'doc_id' => $documentId, 'type' => $linkedType]
        );
    }

    /** @return int[] ids of $documentType documents linked to one record of another module */
    public function documentIdsLinkedTo(string $documentType, string $linkedType, int $linkedId): array
    {
        $rows = $this->tenantFindMany(
            'SELECT document_id FROM finance_document_links WHERE document_type = :doc_type AND linked_type = :type AND linked_id = :id ORDER BY document_id DESC',
            ['doc_type' => $documentType, 'type' => $linkedType, 'id' => $linkedId]
        );

        return array_map(fn (array $r) => (int) $r['document_id'], $rows);
    }

    public function create(string $documentType, int $documentId, string $linkedType, int $linkedId, string $label, ?int $userId): int
    {
        return $this->tenantInsertGetId([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'linked_type' => $linkedType,
            'linked_id' => $linkedId,
            'linked_label' => mb_substr($label, 0, 255),
            'created_by_user_id' => $userId,
        ]);
    }

    /** Removes every link of a document (used when a draft document is deleted). */
    public function deleteForDocument(string $documentType, int $documentId): void
    {
        $this->tenantDelete('document_type = :doc_type AND document_id = :doc_id', ['doc_type' => $documentType, 'doc_id' => $documentId]);
    }

    /** Runs $work atomically, joining the caller's transaction if one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
