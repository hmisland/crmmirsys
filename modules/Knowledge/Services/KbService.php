<?php

declare(strict_types=1);

namespace Modules\Knowledge\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Knowledge\KbArticleRepository;
use Modules\Knowledge\KbCategoryRepository;

final class KbService
{
    public function __construct(
        private readonly KbArticleRepository $articles,
        private readonly KbCategoryRepository $categories,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function categories(): array
    {
        return $this->categories->all();
    }

    public function createCategory(array $actingUser, string $name): array
    {
        $this->authorize($actingUser, Permission::KB_MANAGE);

        if (trim($name) === '') {
            throw new ValidationException(['name' => ['Name is required.']]);
        }

        $id = $this->categories->create(trim($name));

        return $this->categories->findById($id);
    }

    public function deleteCategory(array $actingUser, int $id): void
    {
        $this->authorize($actingUser, Permission::KB_MANAGE);

        $this->categories->deleteCategory($id);
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::KB_VIEW);

        return $this->articles->search($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::KB_VIEW);

        return $this->requireArticle($id);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::KB_MANAGE);

        $validator = $this->validateInput($input);
        if ($validator !== []) {
            throw new ValidationException($validator);
        }

        $id = $this->articles->create([
            'title' => trim((string) $input['title']),
            'body' => (string) $input['body'],
            'category_id' => !empty($input['category_id']) ? (int) $input['category_id'] : null,
            'created_by_user_id' => (int) $actingUser['id'],
        ]);

        return $this->articles->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $this->requireArticle($id);
        $this->authorize($actingUser, Permission::KB_MANAGE);

        $validator = $this->validateInput($input);
        if ($validator !== []) {
            throw new ValidationException($validator);
        }

        $this->articles->updateArticle($id, [
            'title' => trim((string) $input['title']),
            'body' => (string) $input['body'],
            'category_id' => !empty($input['category_id']) ? (int) $input['category_id'] : null,
        ]);

        return $this->articles->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->requireArticle($id);
        $this->authorize($actingUser, Permission::KB_MANAGE);

        $this->articles->deleteArticle($id);
    }

    private function validateInput(array $input): array
    {
        $errors = [];
        if (trim((string) ($input['title'] ?? '')) === '') {
            $errors['title'] = ['Title is required.'];
        }
        if (trim((string) ($input['body'] ?? '')) === '') {
            $errors['body'] = ['Body is required.'];
        }

        return $errors;
    }

    private function requireArticle(int $id): array
    {
        $article = $this->articles->findById($id);
        if ($article === null) {
            throw new NotFoundException('Article not found.');
        }

        return $article;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
