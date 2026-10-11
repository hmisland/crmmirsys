<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyCategoryRepository;

/** Company categories — same rules as ContactCategoryService (managed with companies.manage_categories). */
final class CompanyCategoryService
{
    public function __construct(
        private readonly CompanyCategoryRepository $categories,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function all(): array
    {
        return $this->categories->all();
    }

    public function find(int $id): ?array
    {
        return $this->categories->findById($id);
    }

    public function canManage(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, Permission::COMPANIES_MANAGE_CATEGORIES);
    }

    public function create(array $actingUser, string $name): array
    {
        $this->authorize($actingUser);

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationException(['name' => ['companies.error.category_name']]);
        }

        return $this->categories->findById($this->categories->create($name));
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->authorize($actingUser);
        $this->categories->deleteCategory($id);
    }

    private function authorize(array $actingUser): void
    {
        if (!$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }
}
