<?php

declare(strict_types=1);

namespace Modules\Support\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Support\TicketCategoryRepository;

final class TicketCategoryService
{
    public function __construct(
        private readonly TicketCategoryRepository $categories,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function all(): array
    {
        return $this->categories->all();
    }

    public function create(array $actingUser, string $name): array
    {
        $this->authorize($actingUser, Permission::TICKET_CATEGORIES_MANAGE);

        if (trim($name) === '') {
            throw new ValidationException(['name' => ['Name is required.']]);
        }

        $id = $this->categories->create(trim($name));

        return $this->categories->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::TICKET_CATEGORIES_MANAGE);

        $this->categories->deleteCategory($id);
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
