<?php

declare(strict_types=1);

namespace Modules\Notification\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Notification\NotificationCreditRepository;
use Modules\Notification\NotificationCreditTransactionRepository;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Services\WalletSettingsService;

/**
 * "Charging the Notification module" (per the explicit requirement)
 * is a real transfer: Org Admin's org Wallet is DEBITED and this
 * module's own notification_credits balance is CREDITED by the same
 * amount, in that order — if the credit step somehow failed after a
 * successful wallet debit, the wallet debit is rolled back by
 * crediting it straight back, same pattern already used for Wallet's
 * own user-to-user transfer.
 */
final class NotificationCreditService
{
    public function __construct(
        private readonly NotificationCreditRepository $credits,
        private readonly NotificationCreditTransactionRepository $transactions,
        private readonly WalletService $wallet,
        private readonly WalletSettingsService $walletSettings,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function balance(int $organizationId): string
    {
        return (string) $this->credits->get($organizationId)['balance'];
    }

    public function recentTransactions(int $limit = 50): array
    {
        return $this->transactions->recent($limit);
    }

    public function charge(array $actingUser, string $amount): array
    {
        if (!$this->authorization->can($actingUser, Permission::NOTIFICATIONS_CHARGE)) {
            throw new AuthorizationException();
        }

        if (bccomp($amount, '0', 18) <= 0) {
            throw new ValidationException(['amount' => ['Amount must be greater than zero.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];
        $this->credits->ensureRow($organizationId);

        $currencyId = $this->walletSettings->effectiveTopupLimits($organizationId)['currency_id'];
        $wallet = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], $currencyId);

        // Debit the org admin's own wallet first — WalletService::debit()
        // itself throws ValidationException (insufficient balance,
        // frozen wallet) before anything on the credit side is touched.
        $this->wallet->debit(
            (int) $wallet['id'],
            $amount,
            'adjustment_debit',
            'notification_credit_topup',
            null,
            'Charged the Notifications module',
            (int) $actingUser['id']
        );

        $this->credits->transaction(function () use ($organizationId, $amount, $actingUser) {
            $current = $this->credits->lockForUpdate($organizationId);
            $currentBalance = $current['balance'] ?? '0';
            $newBalance = bcadd($currentBalance, $amount, 18);

            $this->credits->updateBalance($organizationId, $newBalance);
            $this->transactions->record('topup', $amount, $newBalance, null, null, 'Charged from wallet', (int) $actingUser['id']);
        });

        return $this->credits->get($organizationId);
    }

    /**
     * Deducts the cost of ONE notification if there's enough balance;
     * returns false (deducting nothing) if there isn't — the caller
     * treats that as "skip this send," never as an error that stops
     * the rest of a multi-recipient batch.
     */
    public function tryDeduct(int $organizationId, string $cost, string $channel, ?int $referenceId): bool
    {
        if (bccomp($cost, '0', 18) <= 0) {
            return true; // free (e.g. Bale's own API costs nothing) — nothing to deduct
        }

        $this->credits->ensureRow($organizationId);

        return (bool) $this->credits->transaction(function () use ($organizationId, $cost, $channel, $referenceId) {
            $current = $this->credits->lockForUpdate($organizationId);
            $currentBalance = $current['balance'] ?? '0';

            if (bccomp($currentBalance, $cost, 18) < 0) {
                return false;
            }

            $newBalance = bcsub($currentBalance, $cost, 18);
            $this->credits->updateBalance($organizationId, $newBalance);
            $this->transactions->record('usage', '-' . $cost, $newBalance, 'notification', $referenceId, ucfirst($channel) . ' notification sent', null);

            return true;
        });
    }
}
