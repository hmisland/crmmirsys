<?php

declare(strict_types=1);

namespace Modules\Wallet;

use App\Infrastructure\Repositories\BaseRepository;

/**
 * wallet_gift_codes — platform-level (created by the Super Admin, may be
 * redeemed by any organization unless locked to one), so not tenant-scoped.
 */
final class WalletGiftCodeRepository extends BaseRepository
{
    public function create(array $fields): int
    {
        return $this->insertGetId('wallet_gift_codes', $fields);
    }

    public function findByCode(string $code): ?array
    {
        return $this->findOne('SELECT * FROM wallet_gift_codes WHERE code = :code', ['code' => $code]);
    }

    public function codeExists(string $code): bool
    {
        return $this->findByCode($code) !== null;
    }

    /**
     * Claims an active code atomically (only one redeemer can ever win).
     * Returns false when it was not active any more.
     */
    public function claim(int $id, int $userId, int $organizationId): bool
    {
        return $this->update(
            'wallet_gift_codes',
            ['status' => 'redeemed', 'redeemed_at' => date('Y-m-d H:i:s'), 'redeemed_by_user_id' => $userId, 'redeemed_organization_id' => $organizationId],
            "id = :id AND status = 'active'",
            ['id' => $id]
        ) === 1;
    }

    /** Puts a claimed code back (the wallet credit after claiming failed). */
    public function unclaim(int $id): void
    {
        $this->update(
            'wallet_gift_codes',
            ['status' => 'active', 'redeemed_at' => null, 'redeemed_by_user_id' => null, 'redeemed_organization_id' => null],
            "id = :id AND status = 'redeemed'",
            ['id' => $id]
        );
    }

    public function setRedeemedWallet(int $id, int $walletId): void
    {
        $this->update('wallet_gift_codes', ['redeemed_wallet_id' => $walletId], 'id = :id', ['id' => $id]);
    }

    public function revoke(int $id): bool
    {
        return $this->update('wallet_gift_codes', ['status' => 'revoked'], "id = :id AND status = 'active'", ['id' => $id]) === 1;
    }

    public function recent(int $limit = 200): array
    {
        return $this->findMany(
            "SELECT g.*,
                (SELECT code FROM currencies WHERE currencies.id = g.currency_id) AS currency_code,
                (SELECT name FROM organizations WHERE organizations.id = g.organization_id) AS organization_name,
                (SELECT name FROM organizations WHERE organizations.id = g.redeemed_organization_id) AS redeemed_organization_name,
                (SELECT CONCAT(first_name, ' ', COALESCE(last_name, '')) FROM users WHERE users.id = g.redeemed_by_user_id) AS redeemed_by_name
             FROM wallet_gift_codes g ORDER BY g.id DESC LIMIT " . max(1, min($limit, 1000))
        );
    }
}
