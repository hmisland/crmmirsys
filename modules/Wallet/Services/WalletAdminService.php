<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;

/**
 * The org admin's manual override — used for things no automated flow
 * covers yet: crediting back a disputed charge, a goodwill credit, a
 * correction after a support ticket. Every use requires a
 * human-readable reason, always stored on the ledger row itself, and
 * always goes through WalletService (never touches balance directly)
 * so it is exactly as auditable as any other wallet movement.
 */
final class WalletAdminService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function creditUser(array $actingUser, int $targetUserId, int $currencyId, string $amount, string $reason): array
    {
        $this->requireOrgMember($actingUser, $targetUserId);
        $this->authorize($actingUser, Permission::WALLET_ADJUST);
        $this->requireReason($reason);

        $wallet = $this->wallet->getOrCreateWallet((int) $actingUser['organization_id'], $targetUserId, $currencyId);

        return $this->wallet->credit((int) $wallet['id'], $amount, 'adjustment_credit', 'user', (int) $actingUser['id'], $reason, (int) $actingUser['id']);
    }

    public function debitUser(array $actingUser, int $targetUserId, int $currencyId, string $amount, string $reason): array
    {
        $this->requireOrgMember($actingUser, $targetUserId);
        $this->authorize($actingUser, Permission::WALLET_ADJUST);
        $this->requireReason($reason);

        $wallet = $this->wallet->getOrCreateWallet((int) $actingUser['organization_id'], $targetUserId, $currencyId);

        return $this->wallet->debit((int) $wallet['id'], $amount, 'adjustment_debit', 'user', (int) $actingUser['id'], $reason, (int) $actingUser['id']);
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A reason is required for a manual adjustment.']]);
        }
    }

    private function requireOrgMember(array $actingUser, int $targetUserId): array
    {
        $target = $this->users->findById($targetUserId);

        if ($target === null || (int) ($target['organization_id'] ?? 0) !== (int) $actingUser['organization_id']) {
            throw new ValidationException(['user' => ['User not found in your organization.']]);
        }

        return $target;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
