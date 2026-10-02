<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\TenantScopedRepository;

final class WalletTransactionRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'wallet_transactions';
    }

    public function findById(int $id): ?array
    {
        return $this->tenantFindOne('SELECT * FROM wallet_transactions WHERE id = :id', ['id' => $id]);
    }

    public function findByProviderReference(string $gatewayKey, string $providerReference): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM wallet_transactions WHERE gateway_key = :gateway_key AND provider_reference = :ref',
            ['gateway_key' => $gatewayKey, 'ref' => $providerReference]
        );
    }

    public function create(int $walletId, int $userId, string $amount, string $gatewayKey): int
    {
        return $this->tenantInsertGetId([
            'wallet_id' => $walletId,
            'user_id' => $userId,
            'amount' => $amount,
            'gateway_key' => $gatewayKey,
            'status' => 'pending',
        ]);
    }

    public function setProviderReference(int $id, string $providerReference): void
    {
        $this->tenantUpdate(['provider_reference' => $providerReference], 'id = :id', ['id' => $id]);
    }

    /**
     * Atomically claims this transaction for processing — the actual
     * idempotency guard against two nearly-simultaneous callback hits
     * (see the 0006 migration's docblock). Returns true only for
     * whichever caller's UPDATE actually matched the 'pending' row;
     * everyone else gets false and must not proceed.
     */
    public function claimPending(int $id): bool
    {
        $stmt = $this->query(
            "UPDATE wallet_transactions SET status = 'processing' WHERE id = :id AND status = 'pending'",
            ['id' => $id]
        );

        return $stmt->rowCount() === 1;
    }

    public function markCompleted(int $id, ?string $refId, string $gatewayResponse): void
    {
        $this->tenantUpdate([
            'status' => 'completed',
            'ref_id' => $refId,
            'gateway_response' => $gatewayResponse,
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);
    }

    public function markFailed(int $id, string $gatewayResponse): void
    {
        $this->tenantUpdate([
            'status' => 'failed',
            'gateway_response' => $gatewayResponse,
        ], 'id = :id', ['id' => $id]);
    }

    public function forWallet(int $walletId, int $limit = 50): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM wallet_transactions WHERE wallet_id = :wallet_id ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            ['wallet_id' => $walletId]
        );
    }
}
