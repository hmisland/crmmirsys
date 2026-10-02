<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Wallet\WalletOrgSettingsRepository;
use Modules\Wallet\WalletWithdrawalRepository;

/**
 * Funds are debited (and a 'withdrawal' ledger row written) the
 * moment a request is made, not when it's later approved — a member
 * can't request the same money twice while a request is pending. A
 * rejection credits it straight back ('reversal'); approval leaves
 * the debit as-is and just records who signed off, since the money
 * has already left the wallet by design.
 */
final class WalletWithdrawalService
{
    public function __construct(
        private readonly WalletWithdrawalRepository $withdrawals,
        private readonly WalletService $wallet,
        private readonly WalletOrgSettingsRepository $orgSettings,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function request(array $actingUser, int $walletId, string $amount, string $destinationInfo): array
    {
        $this->authorize($actingUser, Permission::WALLET_WITHDRAW_REQUEST);

        $organizationId = (int) $actingUser['organization_id'];
        $wallet = $this->wallet->find($walletId);

        if ($wallet === null || (int) $wallet['user_id'] !== (int) $actingUser['id']) {
            throw new ValidationException(['wallet' => ['Wallet not found.']]);
        }

        $settings = $this->orgSettings->find($organizationId);
        $min = $settings['min_withdrawal'] ?? '10000';
        $max = $settings['max_withdrawal'] ?? '500000000';

        if (bccomp($amount, $min, 18) < 0 || bccomp($amount, $max, 18) > 0) {
            throw new ValidationException(['amount' => ["Amount must be between {$min} and {$max}."]]);
        }

        if (trim($destinationInfo) === '') {
            throw new ValidationException(['destination_info' => ['Provide destination account details.']]);
        }

        $requestId = $this->withdrawals->create($walletId, (int) $actingUser['id'], $amount, trim($destinationInfo));

        // Debit happens AFTER the request row exists so the ledger's
        // reference_id points at a real, already-created request.
        $this->wallet->debit($walletId, $amount, 'withdrawal', 'wallet_withdrawal_request', $requestId, 'Withdrawal requested', (int) $actingUser['id']);

        return $this->withdrawals->findById($requestId);
    }

    public function pendingForOrganization(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::WALLET_WITHDRAWALS_MANAGE);

        return $this->withdrawals->pendingForOrganization();
    }

    public function approve(array $actingUser, int $requestId, ?string $note): array
    {
        return $this->resolve($actingUser, $requestId, 'approved', $note);
    }

    public function markPaid(array $actingUser, int $requestId, ?string $note): array
    {
        $request = $this->requireRequest($requestId);
        $this->authorize($actingUser, Permission::WALLET_WITHDRAWALS_MANAGE);

        if ($request['status'] !== 'approved') {
            throw new ValidationException(['status' => ['Only an approved request can be marked paid.']]);
        }

        $this->withdrawals->setStatus($requestId, 'paid', $note, (int) $actingUser['id']);

        return $this->withdrawals->findById($requestId);
    }

    public function reject(array $actingUser, int $requestId, ?string $note): array
    {
        $request = $this->requireRequest($requestId);
        $this->authorize($actingUser, Permission::WALLET_WITHDRAWALS_MANAGE);

        if ($request['status'] !== 'pending') {
            throw new ValidationException(['status' => ['Only a pending request can be rejected.']]);
        }

        // Refund the debited amount back to the wallet.
        $this->wallet->credit(
            (int) $request['wallet_id'],
            (string) $request['amount'],
            'reversal',
            'wallet_withdrawal_request',
            $requestId,
            'Withdrawal request rejected' . ($note !== null ? ": {$note}" : ''),
            (int) $actingUser['id']
        );

        $this->withdrawals->setStatus($requestId, 'rejected', $note, (int) $actingUser['id']);

        return $this->withdrawals->findById($requestId);
    }

    private function resolve(array $actingUser, int $requestId, string $status, ?string $note): array
    {
        $request = $this->requireRequest($requestId);
        $this->authorize($actingUser, Permission::WALLET_WITHDRAWALS_MANAGE);

        if ($request['status'] !== 'pending') {
            throw new ValidationException(['status' => ['Only a pending request can be approved.']]);
        }

        $this->withdrawals->setStatus($requestId, $status, $note, (int) $actingUser['id']);

        return $this->withdrawals->findById($requestId);
    }

    private function requireRequest(int $id): array
    {
        $request = $this->withdrawals->findById($id);

        if ($request === null) {
            throw new NotFoundException('Withdrawal request not found.');
        }

        return $request;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
