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

        $this->wallet->debit(
            (int) $senderWallet['id'],
            $totalDebit,
            'transfer_out',
            'user',
            $recipientUserId,
            'Transfer to ' . trim($recipient['first_name'] . ' ' . ($recipient['last_name'] ?? '')),
            $senderId
        );

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

        // The fee itself isn't credited anywhere in this pass — it's
        // simply removed from circulation for the sender. A future
        // Finance phase can route fees to a platform revenue wallet;
        // for now it's only visible as its own 'transfer_fee'... —
        // actually not even that: it's bundled into the single debit
        // above (totalDebit = amount + fee) rather than split into a
        // separate ledger line, which is why the ledger shows one
        // "transfer_out" entry for the combined amount, not two. A
        // real fee-revenue account is out of scope for this pass.

        return ['sender' => $this->wallet->find((int) $senderWallet['id']), 'fee' => $fee];
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
