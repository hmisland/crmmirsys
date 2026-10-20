<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\ProductRepository;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly AuthorizationEngine $authorization,
        private readonly ?KalaCatalog $kala = null,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::PRODUCTS_VIEW);

        return $this->products->search($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::PRODUCTS_VIEW);

        $product = $this->products->findById($id);
        if ($product === null) {
            throw new NotFoundException('Product not found.');
        }

        return $product;
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::PRODUCTS_CREATE);

        $fields = $this->validatedFields($input, null);
        $fields['organization_id'] = $actingUser['organization_id'];
        $id = $this->products->create($fields);

        return $this->products->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->authorize($actingUser, Permission::PRODUCTS_EDIT);

        $existing = $this->products->findById($id);
        if ($existing === null) {
            throw new NotFoundException('Product not found.');
        }
        $this->products->updateProduct($id, $this->validatedFields($input, $existing));

        return $this->products->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::PRODUCTS_DELETE);

        $this->products->deleteProduct($id);
    }

    private function validatedFields(array $input, ?array $existing): array
    {
        if (trim((string) ($input['name'] ?? '')) === '') {
            throw new ValidationException(['name' => ['Name is required.']]);
        }

        if (empty($input['currency_id'])) {
            throw new ValidationException(['currency_id' => ['Currency is required.']]);
        }

        $price = (string) ($input['price'] ?? '0');
        if (!is_numeric($price) || bccomp($price, '0', 18) < 0) {
            throw new ValidationException(['price' => ['Price must be a non-negative number.']]);
        }

        return [
            'sku' => isset($input['sku']) && trim((string) $input['sku']) !== '' ? trim((string) $input['sku']) : null,
            'name' => trim((string) $input['name']),
            'description' => isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null,
            'unit' => isset($input['unit']) && trim((string) $input['unit']) !== '' ? trim((string) $input['unit']) : null,
            'currency_id' => (int) $input['currency_id'],
            'price' => $price,
            'tax_category_id' => isset($input['tax_category_id']) && $input['tax_category_id'] !== '' ? (int) $input['tax_category_id'] : null,
            'tax_profile_id' => isset($input['tax_profile_id']) && $input['tax_profile_id'] !== '' ? (int) $input['tax_profile_id'] : null,
            'is_active' => isset($input['is_active']) ? 1 : 0,
        ] + $this->taxFields($input, $existing);
    }

    /**
     * Tax product ID, exempt / taxable and VAT percent (all optional).
     * An ID taken from the national list (source "catalog") is locked: it
     * is kept as it is, whatever the form sends. "From the list" is only
     * believed when kala.db really has that ID — never from a form value
     * alone; otherwise a typed ID is "manual" and stays editable.
     */
    private function taxFields(array $input, ?array $existing): array
    {
        // A request without the tax section (another caller) leaves the saved values alone.
        if ($existing !== null && !array_key_exists('tax_product_code', $input) && !array_key_exists('vat_rate', $input)) {
            return array_intersect_key($existing, array_flip(['tax_product_code', 'tax_product_source', 'vat_exempt', 'vat_rate']));
        }
        $errors = [];
        if (($existing['tax_product_source'] ?? null) === 'catalog') {
            $code = (string) $existing['tax_product_code'];
            $source = 'catalog';
        } else {
            $raw = trim((string) ($input['tax_product_code'] ?? ''));
            $code = $raw === '' ? null : KalaCatalog::code($raw);
            if ($raw !== '' && $code === null) {
                $errors['tax_product_code'] = ['products.error.tax_code'];
            }
            $fromCatalog = $existing === null && ($input['tax_from_catalog'] ?? '') === '1'
                && $code !== null && $this->kala !== null && $this->kala->find($code) !== null;
            $source = $code === null ? null : ($fromCatalog ? 'catalog' : 'manual');
        }

        $exempt = (string) ($input['vat_exempt'] ?? '');
        $rate = trim(strtr((string) ($input['vat_rate'] ?? ''), ['٫' => '.', '/' => '.']));
        if ($rate !== '' && (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) {
            $errors['vat_rate'] = ['products.error.vat_rate'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'tax_product_code' => $code,
            'tax_product_source' => $source,
            'vat_exempt' => in_array($exempt, ['0', '1'], true) ? (int) $exempt : null,
            'vat_rate' => $rate !== '' ? number_format((float) $rate, 2, '.', '') : null,
        ];
    }

    /** Unit of an earlier product of the organization with this tax product ID, for prefilling a new one. */
    public function unitForTaxCode(string $code): ?string
    {
        return $this->products->unitForTaxCode($code);
    }

    /**
     * Product picker for proforma/invoice rows: active products whose
     * code (sku), tax product ID or name contains $query, at most $limit;
     * "sku" in the result is the row code (the tax product ID when set,
     * else the product's own code). Allowed for anyone who can view
     * products or write proformas/invoices.
     *
     * @return array<int, array{id: int, sku: string, name: string, description: string, price: string, unit: string}>
     */
    public function lookup(array $actingUser, string $query, int $limit = 15): array
    {
        $allowed = $this->authorization->can($actingUser, Permission::PRODUCTS_VIEW)
            || $this->authorization->can($actingUser, Permission::QUOTES_CREATE)
            || $this->authorization->can($actingUser, Permission::INVOICES_CREATE);
        if (!$allowed) {
            throw new AuthorizationException();
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rows = $this->products->search(['search' => $query, 'is_active' => true]);

        // The code a row gets: the tax product ID first, then the product's own code (may be empty).
        $code = fn (array $p): string => (string) (($p['tax_product_code'] ?? '') !== '' ? $p['tax_product_code'] : ($p['sku'] ?? ''));

        // Exact code match first, then names starting with the query, then the rest.
        $rank = fn (array $p) => strcasecmp($code($p), $query) === 0 || strcasecmp((string) $p['sku'], $query) === 0 ? 0
            : (mb_stripos((string) $p['name'], $query) === 0 || mb_stripos($code($p), $query) === 0 || mb_stripos((string) $p['sku'], $query) === 0 ? 1 : 2);
        usort($rows, fn ($a, $b) => [$rank($a), $a['name']] <=> [$rank($b), $b['name']]);

        return array_map(fn (array $p) => [
            'id' => (int) $p['id'],
            'sku' => $code($p),
            'name' => (string) $p['name'],
            'description' => (string) ($p['description'] ?? ''),
            'price' => (string) $p['price'],
            'unit' => (string) ($p['unit'] ?? ''),
        ], array_slice($rows, 0, max(1, min($limit, 50))));
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    /**
     * Deletes every id the acting user may delete; the rest are skipped
     * (no permission, not theirs, or locked — e.g. issued / converted).
     * Each one goes through delete() and its own checks.
     *
     * @param array<int|string, mixed> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $actingUser, array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)), 0, 500) as $id) {
            try {
                $this->delete($actingUser, $id);
                $deleted++;
            } catch (AuthorizationException | NotFoundException | ValidationException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
    /**
     * Per-row edit/delete flags for the list page: the same checks
     * update()/delete() apply (permission scope + document state).
     *
     * @return array<int, array{edit: bool, delete: bool, reason?: string}>
     */
    public function rowPermissions(array $actingUser, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $locked = false;
            $result[(int) $row['id']] = [
                'edit' => !$locked && $this->authorization->can($actingUser, Permission::PRODUCTS_EDIT, $row),
                'delete' => !$locked && $this->authorization->can($actingUser, Permission::PRODUCTS_DELETE, $row),
            ] + ($locked ? ['reason' => ''] : []);
        }

        return $result;
    }
}
