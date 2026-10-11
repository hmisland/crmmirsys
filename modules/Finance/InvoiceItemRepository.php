<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;

final class InvoiceItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'invoice_items';
    }

    public function forInvoice(int $invoiceId): array
    {
        return $this->findMany(
            'SELECT ii.*, (SELECT name FROM products WHERE products.id = ii.product_id) AS product_name,
                    (SELECT sku FROM products WHERE products.id = ii.product_id) AS product_sku
             FROM invoice_items ii WHERE ii.invoice_id = :invoice_id ORDER BY ii.position',
            ['invoice_id' => $invoiceId]
        );
    }

    public function deleteAllFor(int $invoiceId): void
    {
        $this->delete($this->table(), 'invoice_id = :invoice_id', ['invoice_id' => $invoiceId]);
    }

    public function create(array $fields): int
    {
        return $this->insertGetId($this->table(), $fields);
    }
}
