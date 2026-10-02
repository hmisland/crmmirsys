<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\BankRepository;

final class BankService
{
    public function __construct(private readonly BankRepository $banks)
    {
    }

    public function all(): array
    {
        return $this->banks->all();
    }

    public function add(array $actingUser, string $name): array
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }

        $name = trim($name);
        if ($name === '') {
            throw new ValidationException(['name' => ['Bank name is required.']]);
        }

        $id = $this->banks->create($name);

        return $this->banks->findById($id);
    }

    public function deactivate(array $actingUser, int $id): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }

        $this->banks->deactivate($id);
    }
}
