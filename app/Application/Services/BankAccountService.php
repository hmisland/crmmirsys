<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\BankRepository;
use App\Infrastructure\Repositories\UserBankAccountRepository;

/**
 * Per the explicit requirement, these accounts must belong to the
 * same person as the profile (same national ID, same mobile phone) —
 * this is stated to the user as a plain-text notice on the form (see
 * auth/profile.php), not something this Service can actually verify
 * automatically (no bank-identity-matching API is connected). A user
 * can add more than one bank account; each carries its own sheba/
 * account/card/branch, per the requirement.
 */
final class BankAccountService
{
    public function __construct(
        private readonly UserBankAccountRepository $accounts,
        private readonly BankRepository $banks,
    ) {
    }

    public function forUser(int $userId): array
    {
        return $this->accounts->forUser($userId);
    }

    public function add(int $userId, int $organizationId, array $input): array
    {
        $bankId = (int) ($input['bank_id'] ?? 0);
        if ($bankId <= 0) {
            throw new ValidationException(['bank_id' => ['Choose a bank.']]);
        }

        $bank = $this->banks->findById($bankId);
        if ($bank === null) {
            throw new ValidationException(['bank_id' => ['That bank is not in the list — ask your Super Admin to add it.']]);
        }

        $sheba = $this->digitsOnly($input['sheba_number'] ?? '');
        if ($sheba !== '' && !preg_match('/^\d{24}$/', $sheba)) {
            throw new ValidationException(['sheba_number' => ['Sheba number must be 24 digits, without "IR".']]);
        }

        // 16 digits, 4 groups of 4 (the real Iranian/Shetab card
        // format) — corrected after an earlier instruction said 12,
        // which would have rejected every real card number.
        $card = $this->digitsOnly($input['card_number'] ?? '');
        if ($card !== '' && !preg_match('/^\d{16}$/', $card)) {
            throw new ValidationException(['card_number' => ['Card number must be 16 digits.']]);
        }

        $accountNumber = trim((string) ($input['account_number'] ?? ''));
        $branchCode = trim((string) ($input['branch_code'] ?? ''));

        if ($sheba === '' && $card === '' && $accountNumber === '') {
            throw new ValidationException(['account_number' => ['Provide at least an account number, card number, or Sheba number.']]);
        }

        $id = $this->accounts->create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'bank_name' => $bank['name'],
            'account_number' => $accountNumber !== '' ? $accountNumber : null,
            'sheba_number' => $sheba !== '' ? $sheba : null,
            'card_number' => $card !== '' ? $card : null,
            'branch_code' => $branchCode !== '' ? $branchCode : null,
        ]);

        return $this->accounts->findById($id);
    }

    public function delete(int $userId, int $accountId): void
    {
        $account = $this->requireOwnAccount($userId, $accountId);
        $this->accounts->deleteAccount((int) $account['id']);
    }

    /** Used by Wallet's withdrawal flow — throws if the account doesn't exist or belongs to someone else. */
    public function requireOwnAccount(int $userId, int $accountId): array
    {
        $account = $this->accounts->findById($accountId);

        if ($account === null || (int) $account['user_id'] !== $userId) {
            throw new NotFoundException('Bank account not found.');
        }

        return $account;
    }

    /** A short, readable summary for a withdrawal request's stored destination text. */
    public function formatForWithdrawal(array $account): string
    {
        $parts = [$account['bank_name']];
        if (!empty($account['account_number'])) {
            $parts[] = 'Account: ' . $account['account_number'];
        }
        if (!empty($account['sheba_number'])) {
            $parts[] = 'Sheba: IR' . $account['sheba_number'];
        }
        if (!empty($account['card_number'])) {
            $parts[] = 'Card: ' . $account['card_number'];
        }
        if (!empty($account['branch_code'])) {
            $parts[] = 'Branch: ' . $account['branch_code'];
        }

        return implode(' | ', $parts);
    }

    private function digitsOnly($value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}
