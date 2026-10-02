<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CurrencyRepository;
use Modules\Wallet\WalletLedgerRepository;
use Modules\Wallet\WalletRepository;

/**
 * Every balance change in the whole Wallet module goes through
 * credit()/debit() here — nothing else ever writes to wallets.balance
 * or wallet_ledger directly. Both always run inside ONE database
 * transaction with the wallet row locked (SELECT ... FOR UPDATE) for
 * its duration, so two concurrent operations on the same wallet
 * (e.g. a transfer and a top-up landing at the same instant) can
 * never read a stale balance and overwrite each other's result — the
 * second one simply waits for the first's transaction to finish. The
 * ledger row's balance_after is written from the LOCKED read, in the
 * same transaction as the balance update, so the two can never
 * disagree.
 */
final class WalletService
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly WalletLedgerRepository $ledger,
        private readonly CurrencyRepository $currencies,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function getOrCreateWallet(int $organizationId, int $userId, int $currencyId): array
    {
        $existing = $this->wallets->findForUser($userId, $currencyId);

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->wallets->create($userId, $currencyId);

        return $this->wallets->findById($id);
    }

    public function defaultCurrency(): array
    {
        return $this->currencies->findByCode('IRR') ?? $this->currencies->all()[0];
    }

    public function find(int $walletId): ?array
    {
        return $this->wallets->findById($walletId);
    }

    public function ledgerFor(int $walletId, int $limit = 100): array
    {
        return $this->ledger->forWallet($walletId, $limit);
    }

    /**
     * @param string $amount a positive decimal string (never a float
     *   — floats lose precision exactly where money can't afford to)
     */
    public function credit(
        int $walletId,
        string $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?int $actorUserId = null
    ): array {
        $this->assertPositiveAmount($amount);

        return $this->wallets->transaction(function () use ($walletId, $amount, $type, $referenceType, $referenceId, $description, $actorUserId) {
            $wallet = $this->wallets->lockForUpdate($walletId);

            if ($wallet === null) {
                throw new ValidationException(['wallet' => ['Wallet not found.']]);
            }

            $newBalance = bcadd($wallet['balance'], $amount, 18);
            $this->wallets->updateBalance($walletId, $newBalance);
            $this->ledger->record($walletId, $type, $amount, $newBalance, $referenceType, $referenceId, $description, $actorUserId);

            return $this->wallets->findById($walletId);
        });
    }

    /**
     * @param string $amount a positive decimal string — the amount
     *   to REMOVE; throws if it would take the wallet negative.
     */
    public function debit(
        int $walletId,
        string $amount,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $description = null,
        ?int $actorUserId = null
    ): array {
        $this->assertPositiveAmount($amount);

        return $this->wallets->transaction(function () use ($walletId, $amount, $type, $referenceType, $referenceId, $description, $actorUserId) {
            $wallet = $this->wallets->lockForUpdate($walletId);

            if ($wallet === null) {
                throw new ValidationException(['wallet' => ['Wallet not found.']]);
            }

            if ($wallet['status'] !== 'active') {
                throw new ValidationException(['wallet' => ['This wallet is frozen.']]);
            }

            if (bccomp($wallet['balance'], $amount, 18) < 0) {
                throw new ValidationException(['amount' => ['Insufficient balance.']]);
            }

            $newBalance = bcsub($wallet['balance'], $amount, 18);
            $this->wallets->updateBalance($walletId, $newBalance);
            $this->ledger->record($walletId, $type, '-' . $amount, $newBalance, $referenceType, $referenceId, $description, $actorUserId);

            return $this->wallets->findById($walletId);
        });
    }

    public function canView(array $actingUser, array $wallet): bool
    {
        return $this->authorization->can($actingUser, Permission::WALLET_VIEW, ['owner_id' => $wallet['user_id']]);
    }

    private function assertPositiveAmount(string $amount): void
    {
        if (bccomp($amount, '0', 18) <= 0) {
            throw new ValidationException(['amount' => ['Amount must be greater than zero.']]);
        }
    }
}
