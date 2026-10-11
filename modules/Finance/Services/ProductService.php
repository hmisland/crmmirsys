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

        $fields = $this->validatedFields($input);
        $fields['organization_id'] = $actingUser['organization_id'];
        $id = $this->products->create($fields);

        return $this->products->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->authorize($actingUser, Permission::PRODUCTS_EDIT);

        $this->products->updateProduct($id, $this->validatedFields($input));

        return $this->products->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::PRODUCTS_DELETE);

        $this->products->deleteProduct($id);
    }

    private function validatedFields(array $input): array
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
        ];
    }

    /**
     * Product picker for proforma/invoice rows: active products whose
     * code (sku) or name contains $query, at most $limit. Allowed for
     * anyone who can view products or write proformas/invoices.
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

        // Exact code match first, then names starting with the query, then the rest.
        $rank = fn (array $p) => strcasecmp((string) $p['sku'], $query) === 0 ? 0
            : (mb_stripos((string) $p['name'], $query) === 0 || mb_stripos((string) $p['sku'], $query) === 0 ? 1 : 2);
        usort($rows, fn ($a, $b) => [$rank($a), $a['name']] <=> [$rank($b), $b['name']]);

        return array_map(fn (array $p) => [
            'id' => (int) $p['id'],
            'sku' => (string) ($p['sku'] ?? ''),
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
