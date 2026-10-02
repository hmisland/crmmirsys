<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class BankRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'banks';
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM banks WHERE is_active = 1 ORDER BY name');
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM banks WHERE id = :id', ['id' => $id]);
    }

    public function create(string $name): int
    {
        return $this->insertGetId($this->table(), ['name' => $name]);
    }

    public function deactivate(int $id): void
    {
        $this->update($this->table(), ['is_active' => 0], 'id = :id', ['id' => $id]);
    }
}
