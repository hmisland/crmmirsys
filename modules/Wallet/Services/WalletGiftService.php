<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Wallet\WalletGiftCodeRepository;

/**
 * Gift credit.
 *  - Super Admin generates gift codes (amount, optional organization
 *    lock, optional expiry) and hands them to an org admin.
 *  - An org admin redeems a code: their own wallet is credited as
 *    "gift_credit". Each code works once (claimed atomically).
 *  - An org admin sends gift credit to a colleague: their wallet is
 *    debited ("gift_sent") and the colleague's credited ("gift_credit");
 *    if the credit fails the debit is reversed. No transfer fee.
 * Org admin actions require wallet.adjust (org-admin only).
 */
final class WalletGiftService
{
    // No 0/O/1/I/L — easy to read out and type.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly WalletGiftCodeRepository $codes,
        private readonly WalletService $wallet,
        private readonly WalletSettingsService $settings,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    // --- Super Admin --------------------------------------------------------

    /**
     * @return string[] the generated codes
     */
    public function generate(array $actingUser, string $amount, int $count, ?int $organizationId, ?string $expiresAt, string $note): array
    {
        $this->requireSuperAdmin($actingUser);

        $amount = $this->validAmount($amount);
        $count = max(1, min($count, 100));
        if ($expiresAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
            $expiresAt = null;
        }

        $currencyId = (int) ($this->settings->platformSettings()['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            throw new ValidationException(['amount' => ['wallet_gift.error_no_currency']]);
        }
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            do {
                $code = $this->newCode();
            } while ($this->codes->codeExists($code));

            $this->codes->create([
                'code' => $code,
                'amount' => $amount,
                'currency_id' => $currencyId,
                'organization_id' => $organizationId,
                'note' => trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'expires_at' => $expiresAt,
                'created_by_user_id' => (int) $actingUser['id'],
            ]);
            $created[] = $code;
        }

        return $created;
    }

    public function revoke(array $actingUser, int $id): bool
    {
        $this->requireSuperAdmin($actingUser);

        return $this->codes->revoke($id);
    }

    public function list(array $actingUser): array
    {
        $this->requireSuperAdmin($actingUser);

        return $this->codes->recent();
    }

    // --- Org admin ----------------------------------------------------------

    /** @return array{amount: string, wallet: array} */
    public function redeem(array $actingUser, string $code): array
    {
        $this->requireOrgAdmin($actingUser);
        $organizationId = (int) $actingUser['organization_id'];

        // Accept "GIFT-ABCD-EFGH-JKMN", "gift abcd efgh jkmn" or just the 12 characters.
        $body = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($body) === 16 && str_starts_with($body, 'GIFT')) {
            $body = substr($body, 4);
        }
        $code = strlen($body) === 12 ? 'GIFT-' . substr($body, 0, 4) . '-' . substr($body, 4, 4) . '-' . substr($body, 8, 4) : '';

        $gift = $code !== '' ? $this->codes->findByCode($code) : null;
        if ($gift === null) {
            throw new ValidationException(['code' => ['wallet_gift.error_not_found']]);
        }
        if ($gift['status'] !== 'active') {
            throw new ValidationException(['code' => [$gift['status'] === 'redeemed' ? 'wallet_gift.error_used' : 'wallet_gift.error_revoked']]);
        }
        if ($gift['expires_at'] !== null && $gift['expires_at'] < \App\Infrastructure\Time\OrganizationTimezone::shared()->today($organizationId)) {
            throw new ValidationException(['code' => ['wallet_gift.error_expired']]);
        }
        if ($gift['organization_id'] !== null && (int) $gift['organization_id'] !== $organizationId) {
            throw new ValidationException(['code' => ['wallet_gift.error_other_org']]);
        }

        if (!$this->codes->claim((int) $gift['id'], (int) $actingUser['id'], $organizationId)) {
            throw new ValidationException(['code' => ['wallet_gift.error_used']]);
        }

        try {
            $wallet = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], (int) $gift['currency_id']);
            $wallet = $this->wallet->credit((int) $wallet['id'], (string) $gift['amount'], 'gift_credit', 'gift_code', (int) $gift['id'], 'Gift code ' . $gift['code'], (int) $actingUser['id']);
        } catch (\Throwable $e) {
            $this->codes->unclaim((int) $gift['id']);
            throw $e;
        }

        $this->codes->setRedeemedWallet((int) $gift['id'], (int) $wallet['id']);

        return ['amount' => (string) $gift['amount'], 'wallet' => $wallet];
    }

    public function sendToColleague(array $actingUser, int $recipientUserId, string $amount, string $note): void
    {
        $this->requireOrgAdmin($actingUser);
        $organizationId = (int) $actingUser['organization_id'];
        $amount = $this->validAmount($amount);

        $recipient = $this->users->findById($recipientUserId);
        if ($recipient === null || (int) ($recipient['organization_id'] ?? 0) !== $organizationId || $recipient['status'] !== 'active') {
            throw new ValidationException(['recipient_user_id' => ['wallet_gift.error_recipient']]);
        }
        if ($recipientUserId === (int) $actingUser['id']) {
            throw new ValidationException(['recipient_user_id' => ['wallet_gift.error_self']]);
        }

        $currencyId = (int) $this->settings->effectiveTopupLimits($organizationId)['currency_id'];
        $from = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], $currencyId);
        $to = $this->wallet->getOrCreateWallet($organizationId, $recipientUserId, $currencyId);
        $label = trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;
        $recipientName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
        $senderName = trim(($actingUser['first_name'] ?? '') . ' ' . ($actingUser['last_name'] ?? ''));

        // Debit first: throws (insufficient balance, frozen) before anything is credited.
        $this->wallet->debit((int) $from['id'], $amount, 'gift_sent', 'gift_transfer', $recipientUserId,
            'Gift credit to ' . $recipientName . ($label !== null ? ' — ' . $label : ''), (int) $actingUser['id']);

        try {
            $this->wallet->credit((int) $to['id'], $amount, 'gift_credit', 'gift_transfer', (int) $actingUser['id'],
                'Gift credit from ' . $senderName . ($label !== null ? ' — ' . $label : ''), (int) $actingUser['id']);
        } catch (\Throwable $e) {
            $this->wallet->credit((int) $from['id'], $amount, 'reversal', 'gift_transfer', $recipientUserId, 'Gift credit reversed', (int) $actingUser['id']);
            throw $e;
        }
    }

    public function canManage(array $actingUser): bool
    {
        return $this->authorization->can($actingUser, Permission::WALLET_ADJUST);
    }

    // --- Internals ------------------------------------------------------------

    private function newCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'GIFT-' . substr($chars, 0, 4) . '-' . substr($chars, 4, 4) . '-' . substr($chars, 8, 4);
    }

    private function validAmount(string $amount): string
    {
        $amount = trim(str_replace(',', '', $amount));
        if (!preg_match('/^\d+(\.\d{1,3})?$/', $amount) || bccomp($amount, '0', 3) <= 0) {
            throw new ValidationException(['amount' => ['wallet_gift.error_amount']]);
        }

        return $amount;
    }

    private function requireSuperAdmin(array $actingUser): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }

    private function requireOrgAdmin(array $actingUser): void
    {
        if (empty($actingUser['organization_id']) || !$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }
}
