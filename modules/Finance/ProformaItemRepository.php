<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;

/**
 * Not tenant-scoped itself (no organization_id column) — every call
 * is always reached through an already-tenant-verified proforma_id,
 * the same pattern Ticket replies/Task comments use for their own
 * child tables.
 */
final class ProformaItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'proforma_items';
    }

    public function forProforma(int $proformaId): array
    {
        return $this->findMany(
            'SELECT pi.*, (SELECT name FROM products WHERE products.id = pi.product_id) AS product_name,
                    (SELECT COALESCE(NULLIF(tax_product_code, \'\'), NULLIF(sku, \'\')) FROM products WHERE products.id = pi.product_id) AS product_sku
             FROM proforma_items pi WHERE pi.proforma_id = :proforma_id ORDER BY pi.position',
            ['proforma_id' => $proformaId]
        );
    }

    public function deleteAllFor(int $proformaId): void
    {
        $this->delete($this->table(), 'proforma_id = :proforma_id', ['proforma_id' => $proformaId]);
    }

    public function create(array $fields): int
    {
        return $this->insertGetId($this->table(), $fields);
    }
}
