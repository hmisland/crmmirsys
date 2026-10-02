<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Wallet\Gateways\GatewayRegistry;
use Modules\Wallet\WalletTransactionRepository;

final class WalletTopUpService
{
    public function __construct(
        private readonly WalletTransactionRepository $transactions,
        private readonly WalletService $wallet,
        private readonly WalletSettingsService $settings,
        private readonly GatewayRegistry $gateways,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /** @return array{redirectUrl: string} */
    public function initiate(array $actingUser, string $gatewayKey, string $amount, string $callbackUrl): array
    {
        $this->authorize($actingUser, Permission::WALLET_TOPUP);

        $organizationId = (int) $actingUser['organization_id'];
        $this->settings->assertTopupAmountAllowed($organizationId, $amount);

        $gateway = $this->gateways->get($gatewayKey);
        if ($gateway === null) {
            throw new ValidationException(['gateway' => ['Unknown payment gateway.']]);
        }

        $currencyId = $this->settings->effectiveTopupLimits($organizationId)['currency_id'];
        $wallet = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], $currencyId);

        $transactionId = $this->transactions->create($wallet['id'], (int) $actingUser['id'], $amount, $gatewayKey);

        // ZarinPal's amount is a whole-unit integer (Rials) — bcmath
        // strings are used everywhere else for precision, but the
        // gateway boundary itself needs a plain int per its API.
        $result = $gateway->initiate(
            (int) $amount,
            $callbackUrl . '?tx=' . $transactionId,
            'Wallet top-up #' . $transactionId,
            ['mobile' => $actingUser['phone'] ?? null, 'email' => $actingUser['email'] ?? null]
        );

        if (!$result->success) {
            $this->transactions->markFailed($transactionId, $result->rawResponse);

            throw new ValidationException(['gateway' => [$result->errorMessage ?? 'Could not start payment.']]);
        }

        $this->transactions->setProviderReference($transactionId, $result->reference);

        return ['redirectUrl' => $result->redirectUrl];
    }

    /**
     * Handles the gateway's redirect back. Idempotent by construction:
     * a transaction already marked 'completed' is simply returned as
     * success without touching the wallet again — the unique DB
     * constraint on (gateway_key, provider_reference) plus this status
     * check together mean a duplicated callback (double-click, a
     * retried webhook, someone replaying the URL) can never credit
     * twice.
     */
    public function handleCallback(int $transactionId, string $gatewayKey, array $callbackParams): array
    {
        $tx = $this->transactions->findById($transactionId);

        if ($tx === null || $tx['gateway_key'] !== $gatewayKey) {
            throw new ValidationException(['transaction' => ['Top-up not found.']]);
        }

        if ($tx['status'] === 'completed') {
            return ['success' => true, 'alreadyProcessed' => true, 'wallet' => $this->wallet->find((int) $tx['wallet_id'])];
        }

        if ($tx['status'] === 'failed' || $tx['provider_reference'] === null) {
            throw new ValidationException(['transaction' => ['This top-up cannot be completed.']]);
        }

        // Atomically claim it — see WalletTransactionRepository::claimPending()'s
        // docblock. If we lose the claim, someone else (a near-simultaneous
        // duplicate hit on this same callback) is already handling it.
        if (!$this->transactions->claimPending($transactionId)) {
            return ['success' => true, 'alreadyProcessed' => true, 'wallet' => $this->wallet->find((int) $tx['wallet_id'])];
        }

        $gateway = $this->gateways->get($gatewayKey);
        if ($gateway === null) {
            throw new ValidationException(['gateway' => ['Unknown payment gateway.']]);
        }

        $result = $gateway->verify($callbackParams, (int) $tx['amount'], $tx['provider_reference']);

        if (!$result->success) {
            $this->transactions->markFailed($transactionId, $result->rawResponse);

            throw new ValidationException(['transaction' => [$result->errorMessage ?? 'Payment verification failed.']]);
        }

        $this->transactions->markCompleted($transactionId, $result->refId, $result->rawResponse);

        $wallet = $this->wallet->credit(
            (int) $tx['wallet_id'],
            (string) $tx['amount'],
            'topup',
            'wallet_transaction',
            $transactionId,
            'Top-up via ' . $gatewayKey . ($result->refId !== null ? ' (ref ' . $result->refId . ')' : ''),
            (int) $tx['user_id']
        );

        return ['success' => true, 'alreadyProcessed' => false, 'wallet' => $wallet];
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
