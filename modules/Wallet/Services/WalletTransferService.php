<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Wallet\WalletOrgSettingsRepository;

final class WalletTransferService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly WalletSettingsService $settingsService,
        private readonly WalletOrgSettingsRepository $orgSettings,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /**
     * Debits the sender first, then credits the recipient. If the
     * credit somehow fails after a successful debit (should be rare —
     * both run through the same WalletService, the recipient's wallet
     * just needs to exist/be creatable), the sender's debit is rolled
     * back by crediting them back with a 'reversal' entry rather than
     * leaving money in limbo — the ledger shows exactly what happened
     * either way, never a silent loss.
     */
    public function transfer(array $actingUser, int $recipientUserId, string $amount): array
    {
        $this->authorize($actingUser, Permission::WALLET_TRANSFER);

        $organizationId = (int) $actingUser['organization_id'];
        $senderId = (int) $actingUser['id'];

        if ($recipientUserId === $senderId) {
            throw new ValidationException(['recipient' => ['You cannot transfer to yourself.']]);
        }

        $recipient = $this->users->findById($recipientUserId);
        if ($recipient === null || (int) ($recipient['organization_id'] ?? 0) !== $organizationId) {
            throw new ValidationException(['recipient' => ['Recipient not found in your organization.']]);
        }

        $settings = $this->orgSettings->find($organizationId);
        $feeFixed = $settings['transfer_fee_fixed'] ?? '0';
        $feePercent = $settings['transfer_fee_percent'] ?? '0';
        $fee = bcadd($feeFixed, bcdiv(bcmul($amount, $feePercent, 18), '100', 18), 18);
        $totalDebit = bcadd($amount, $fee, 18);

        $currencyId = $this->settingsService->effectiveTopupLimits($organizationId)['currency_id'];
        $senderWallet = $this->wallet->getOrCreateWallet($organizationId, $senderId, $currencyId);
        $recipientWallet = $this->wallet->getOrCreateWallet($organizationId, $recipientUserId, $currencyId);

        // Fail early (before any ledger line) when amount + fee isn't covered.
        if (bccomp((string) $senderWallet['balance'], $totalDebit, 18) < 0) {
            throw new ValidationException(['amount' => ['Insufficient balance.']]);
        }

        $recipientName = trim($recipient['first_name'] . ' ' . ($recipient['last_name'] ?? ''));

        // The transferred amount and the fee are separate ledger lines so
        // the report shows exactly what the recipient got and what the fee was.
        $this->wallet->debit((int) $senderWallet['id'], $amount, 'transfer_out', 'user', $recipientUserId, 'Transfer to ' . $recipientName, $senderId);

        if (bccomp($fee, '0', 18) > 0) {
            try {
                $this->wallet->debit((int) $senderWallet['id'], $fee, 'transfer_fee', 'user', $recipientUserId, 'Transfer fee: ' . $recipientName, $senderId);
            } catch (\Throwable $e) {
                $this->wallet->credit((int) $senderWallet['id'], $amount, 'reversal', 'user', $recipientUserId, 'Reversed failed transfer', $senderId);

                throw $e;
            }
        }

        try {
            $this->wallet->credit(
                (int) $recipientWallet['id'],
                $amount,
                'transfer_in',
                'user',
                $senderId,
                'Transfer from ' . trim($actingUser['first_name'] . ' ' . ($actingUser['last_name'] ?? '')),
                $senderId
            );
        } catch (\Throwable $e) {
            $this->wallet->credit((int) $senderWallet['id'], $totalDebit, 'reversal', 'user', $recipientUserId, 'Reversed failed transfer', $senderId);

            throw $e;
        }

        // The fee isn't credited anywhere yet — it's removed from
        // circulation for the sender. A fee-revenue account is out of scope.

        return ['sender' => $this->wallet->find((int) $senderWallet['id']), 'fee' => $fee];
    }

    /** Fee settings for the transfer form hint: ['fixed' => string, 'percent' => string]. */
    public function feeFor(int $organizationId): array
    {
        $settings = $this->orgSettings->find($organizationId);

        return ['fixed' => (string) ($settings['transfer_fee_fixed'] ?? '0'), 'percent' => (string) ($settings['transfer_fee_percent'] ?? '0')];
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
