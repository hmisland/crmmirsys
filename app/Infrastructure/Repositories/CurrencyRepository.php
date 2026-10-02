<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class CurrencyRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'currencies';
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM currencies WHERE is_active = 1 ORDER BY code');
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM currencies WHERE id = :id', ['id' => $id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->findOne('SELECT * FROM currencies WHERE code = :code', ['code' => $code]);
    }
}
