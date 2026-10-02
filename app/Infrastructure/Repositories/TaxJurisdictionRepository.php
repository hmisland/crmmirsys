<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class TaxJurisdictionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'tax_jurisdictions';
    }

    public function all(): array
    {
        return $this->findMany('SELECT * FROM tax_jurisdictions ORDER BY name');
    }

    public function forCountry(int $countryId): array
    {
        return $this->findMany('SELECT * FROM tax_jurisdictions WHERE country_id = :country_id ORDER BY name', ['country_id' => $countryId]);
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM tax_jurisdictions WHERE id = :id', ['id' => $id]);
    }
}
