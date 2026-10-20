<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\TenantScopedRepository;

/** product_tax_favorites: the organization's "frequently used" national-list entries (tenant-scoped). */
final class ProductTaxFavoriteRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'product_tax_favorites';
    }

    public function all(): array
    {
        return $this->tenantFindMany('SELECT * FROM product_tax_favorites WHERE 1 = 1 ORDER BY id DESC');
    }

    /** @param array{id: string, title: string, vat: ?int, taxable: ?string} $item a kala.db entry */
    public function add(array $item, int $userId): void
    {
        if ($this->tenantFindOne('SELECT id FROM product_tax_favorites WHERE tax_product_code = :code', ['code' => $item['id']]) !== null) {
            return;
        }
        $this->tenantInsertGetId([
            'tax_product_code' => $item['id'],
            'title' => mb_substr($item['title'], 0, 500),
            'vat' => $item['vat'],
            'taxable' => $item['taxable'] !== null ? mb_substr($item['taxable'], 0, 60) : null,
            'created_by_user_id' => $userId,
        ]);
    }

    public function remove(string $code): void
    {
        $this->tenantDelete('tax_product_code = :code', ['code' => $code]);
    }
}
