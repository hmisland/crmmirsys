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

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
