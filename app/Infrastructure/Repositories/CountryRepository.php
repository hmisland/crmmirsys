<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class CountryRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'countries';
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM countries ORDER BY name');
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM countries WHERE id = :id', ['id' => $id]);
    }
}
