<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Concurrency-safe document numbers per organization and document type
 * (table number_series, core migration 0076). Same row-lock pattern as
 * Finance's InvoiceNumberSeriesRepository — never MAX()+1.
 *
 * next() joins the caller's transaction when one is open (so a number
 * is only consumed if the caller's whole operation commits), otherwise
 * it runs in its own. The FOR UPDATE lock is held until that
 * transaction ends.
 */
final class NumberSeriesRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'number_series';
    }

    /** e.g. next('contract', 'CTR-') => "CTR-0001", then "CTR-0002", ... */
    public function next(string $documentType, string $defaultPrefix = '', int $defaultPadding = 4): string
    {
        $work = function () use ($documentType, $defaultPrefix, $defaultPadding): string {
            $row = $this->lock($documentType);

            if ($row === null) {
                // INSERT IGNORE: two first-ever requests racing both end up
                // locking the single row the winner created.
                $this->query(
                    'INSERT IGNORE INTO number_series (organization_id, document_type, prefix, padding, next_sequence)
                     VALUES (:org_id, :type, :prefix, :padding, 1)',
                    ['org_id' => OrganizationContext::id(), 'type' => $documentType, 'prefix' => $defaultPrefix, 'padding' => max(1, min(10, $defaultPadding))]
                );
                $row = $this->lock($documentType);
            }

            $sequence = (int) $row['next_sequence'];
            $this->tenantUpdate(['next_sequence' => $sequence + 1], 'id = :id', ['id' => (int) $row['id']]);

            return $row['prefix'] . str_pad((string) $sequence, (int) $row['padding'], '0', STR_PAD_LEFT);
        };

        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    private function lock(string $documentType): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM number_series WHERE document_type = :type FOR UPDATE',
            ['type' => $documentType]
        );
    }
}
