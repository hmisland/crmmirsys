<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Wallet\WalletLedgerRepository;

final class WalletLedgerAccessService
{
    public function __construct(
        private readonly WalletLedgerRepository $ledger,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function forOrganization(array $actingUser, int $limit = 100): array
    {
        if (!$this->authorization->can($actingUser, Permission::WALLET_TRANSACTIONS_VIEW)) {
            throw new AuthorizationException();
        }

        return $this->ledger->forOrganizationWithOwnerNames($limit);
    }
}
