<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\ExchangeRateRepository;

/**
 * Deliberately Core, not Finance-owned — Wallet may need this too one
 * day, and neither module should duplicate it. Every rate is a real
 * row a Super Admin entered (or, later, an API-connected job wrote,
 * via the same `rate_source` column) — nothing here is a hardcoded
 * number in logic.
 */
final class ExchangeRateService
{
    public function __construct(private readonly ExchangeRateRepository $rates)
    {
    }

    public function currentSummary(): array
    {
        return $this->rates->currentRatesSummary();
    }

    public function history(int $sourceCurrencyId, int $destinationCurrencyId): array
    {
        return $this->rates->history($sourceCurrencyId, $destinationCurrencyId);
    }

    /** Returns null if no rate has ever been set for this pair — callers must decide how to handle that, never assume 1:1. */
    public function latestRate(int $sourceCurrencyId, int $destinationCurrencyId): ?array
    {
        return $this->rates->latestRate($sourceCurrencyId, $destinationCurrencyId);
    }

    public function setRate(array $actingUser, int $sourceCurrencyId, int $destinationCurrencyId, string $rate): array
    {
        $this->requireSuperAdmin($actingUser);

        if (!is_numeric($rate) || bccomp($rate, '0', 18) <= 0) {
            throw new ValidationException(['rate' => ['Rate must be a positive number.']]);
        }

        if ($sourceCurrencyId === $destinationCurrencyId) {
            throw new ValidationException(['destination_currency_id' => ['Source and destination currencies must differ.']]);
        }

        $id = $this->rates->create($sourceCurrencyId, $destinationCurrencyId, $rate, 'manual', (int) $actingUser['id']);

        return $this->rates->latestRate($sourceCurrencyId, $destinationCurrencyId) ?? ['id' => $id];
    }

    private function requireSuperAdmin(array $actingUser): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
