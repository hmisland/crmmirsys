<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ExchangeRateRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'exchange_rates';
    }

    /**
     * The rate to use RIGHT NOW for a currency pair — the most
     * recent row whose effective_at isn't in the future. Historical
     * transactions never call this; they use whatever rate they
     * already froze onto themselves at the time (per Phase 12's
     * immutability rule) — this is only for a brand-new calculation.
     */
    public function latestRate(int $sourceCurrencyId, int $destinationCurrencyId): ?array
    {
        return $this->findOne(
            'SELECT * FROM exchange_rates
             WHERE source_currency_id = :src AND destination_currency_id = :dst AND effective_at <= NOW()
             ORDER BY effective_at DESC LIMIT 1',
            ['src' => $sourceCurrencyId, 'dst' => $destinationCurrencyId]
        );
    }

    /**
     * Every pair's current rate, one row per pair — for the
     * management page's summary list.
     */
    public function currentRatesSummary(): array
    {
        return $this->findMany(
            "SELECT er.*,
                (SELECT code FROM currencies WHERE currencies.id = er.source_currency_id) AS source_code,
                (SELECT code FROM currencies WHERE currencies.id = er.destination_currency_id) AS destination_code,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = er.created_by_user_id) AS created_by_name
             FROM exchange_rates er
             WHERE er.id IN (
                 SELECT MAX(id) FROM exchange_rates GROUP BY source_currency_id, destination_currency_id
             )
             ORDER BY source_code, destination_code"
        );
    }

    public function history(int $sourceCurrencyId, int $destinationCurrencyId, int $limit = 50): array
    {
        return $this->findMany(
            'SELECT * FROM exchange_rates WHERE source_currency_id = :src AND destination_currency_id = :dst ORDER BY effective_at DESC LIMIT ' . max(1, min(200, $limit)),
            ['src' => $sourceCurrencyId, 'dst' => $destinationCurrencyId]
        );
    }

    public function create(int $sourceCurrencyId, int $destinationCurrencyId, string $rate, string $rateSource, int $createdByUserId): int
    {
        return $this->insertGetId($this->table(), [
            'source_currency_id' => $sourceCurrencyId,
            'destination_currency_id' => $destinationCurrencyId,
            'rate' => $rate,
            'rate_source' => $rateSource,
            'created_by_user_id' => $createdByUserId,
        ]);
    }
}
