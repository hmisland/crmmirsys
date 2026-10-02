<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Repositories\WalletPlatformSettingsRepository;
use Modules\Wallet\WalletOrgSettingsRepository;

/**
 * Per the explicit requirement: min/max top-up is set at TWO levels —
 * Super Admin's platform-wide cap, and each Org Admin's own setting
 * for their organization, which can only ever be tighter than (never
 * exceed) the platform cap. effectiveLimits() is what every top-up
 * actually gets validated against — it reads the org's own setting if
 * one exists, clamps it into the platform's range regardless (in case
 * the platform cap was tightened AFTER the org set its own value),
 * and falls back to the platform's numbers if the org never set
 * anything.
 */
final class WalletSettingsService
{
    public function __construct(
        private readonly WalletPlatformSettingsRepository $platformSettings,
        private readonly WalletOrgSettingsRepository $orgSettings,
        private readonly CurrencyRepository $currencies,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function platformSettings(): array
    {
        return $this->platformSettings->get();
    }

    public function updatePlatformSettings(array $actingUser, int $currencyId, string $minTopup, string $maxTopup): void
    {
        if (empty($actingUser['is_super_admin'])) {
            throw new AuthorizationException();
        }

        $this->assertRange($minTopup, $maxTopup);

        $this->platformSettings->updateSettings($currencyId, $minTopup, $maxTopup);
    }

    public function orgSettings(int $organizationId): ?array
    {
        return $this->orgSettings->find($organizationId);
    }

    public function updateOrgSettings(array $actingUser, int $organizationId, array $fields): void
    {
        $this->authorize($actingUser, Permission::WALLET_SETTINGS_MANAGE);

        $platform = $this->platformSettings->get();

        $minTopup = $this->clamp($fields['min_topup'], $platform['min_topup'], $platform['max_topup']);
        $maxTopup = $this->clamp($fields['max_topup'], $platform['min_topup'], $platform['max_topup']);

        // These four were previously passed straight through to the
        // database with no validation at all — a value outside what
        // its column can actually hold (transfer_fee_percent is
        // DECIMAL(5,2), max 999.99) surfaced as a raw SQL error
        // instead of a friendly message. Fixed here rather than only
        // in the form's HTML, since the HTML constraint alone is
        // trivially bypassed and the real column limit still needs
        // enforcing server-side either way.
        $minWithdrawal = $this->nonNegative($fields['min_withdrawal'] ?? '10000', 'min_withdrawal');
        $maxWithdrawal = $this->nonNegative($fields['max_withdrawal'] ?? '500000000', 'max_withdrawal');
        $transferFeeFixed = $this->nonNegative($fields['transfer_fee_fixed'] ?? '0', 'transfer_fee_fixed');
        $transferFeePercent = $this->boundedPercent($fields['transfer_fee_percent'] ?? '0');

        if (bccomp($minWithdrawal, $maxWithdrawal, 18) > 0) {
            throw new ValidationException(['min_withdrawal' => ['Minimum cannot be greater than maximum.']]);
        }

        if (bccomp($minTopup, $maxTopup, 18) > 0) {
            throw new ValidationException(['min_topup' => ['Minimum cannot be greater than maximum.']]);
        }

        $this->orgSettings->upsert($organizationId, [
            'min_topup' => $minTopup,
            'max_topup' => $maxTopup,
            'min_withdrawal' => $minWithdrawal,
            'max_withdrawal' => $maxWithdrawal,
            'transfer_fee_fixed' => $transferFeeFixed,
            'transfer_fee_percent' => $transferFeePercent,
        ]);
    }

    /** @return array{min_topup: string, max_topup: string, currency_id: int} */
    public function effectiveTopupLimits(int $organizationId): array
    {
        $platform = $this->platformSettings->get();
        $org = $this->orgSettings->find($organizationId);

        if ($org === null) {
            return ['min_topup' => $platform['min_topup'], 'max_topup' => $platform['max_topup'], 'currency_id' => (int) $platform['currency_id']];
        }

        return [
            'min_topup' => $this->clamp($org['min_topup'], $platform['min_topup'], $platform['max_topup']),
            'max_topup' => $this->clamp($org['max_topup'], $platform['min_topup'], $platform['max_topup']),
            'currency_id' => (int) $platform['currency_id'],
        ];
    }

    public function assertTopupAmountAllowed(int $organizationId, string $amount): void
    {
        $limits = $this->effectiveTopupLimits($organizationId);

        if (bccomp($amount, $limits['min_topup'], 18) < 0 || bccomp($amount, $limits['max_topup'], 18) > 0) {
            throw new ValidationException(['amount' => ["Amount must be between {$limits['min_topup']} and {$limits['max_topup']}."]]);
        }
    }

    private function nonNegative(string $value, string $field): string
    {
        if (!is_numeric($value) || bccomp($value, '0', 18) < 0) {
            throw new ValidationException([$field => ['Must be a number, zero or greater.']]);
        }

        return $value;
    }

    /**
     * transfer_fee_percent is DECIMAL(5,2) at the schema level (max
     * 999.99), but a fee percentage over 100 makes no business sense
     * regardless — clamped to a sane 0-100 range rather than merely
     * the column's own wider technical limit.
     */
    private function boundedPercent(string $value): string
    {
        if (!is_numeric($value)) {
            throw new ValidationException(['transfer_fee_percent' => ['Must be a number.']]);
        }

        if (bccomp($value, '0', 2) < 0) {
            return '0';
        }
        if (bccomp($value, '100', 2) > 0) {
            return '100';
        }

        return $value;
    }

    private function clamp(string $value, string $min, string $max): string
    {
        if (bccomp($value, $min, 18) < 0) {
            return $min;
        }
        if (bccomp($value, $max, 18) > 0) {
            return $max;
        }

        return $value;
    }

    private function assertRange(string $min, string $max): void
    {
        if (bccomp($min, $max, 18) > 0) {
            throw new ValidationException(['min_topup' => ['Minimum cannot be greater than maximum.']]);
        }
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
