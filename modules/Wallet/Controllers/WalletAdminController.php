<?php

declare(strict_types=1);

namespace Modules\Wallet\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Wallet\Services\WalletAdminService;
use Modules\Wallet\Services\WalletLedgerAccessService;
use Modules\Wallet\Services\WalletSettingsService;
use Modules\Wallet\Services\WalletWithdrawalService;

final class WalletAdminController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly WalletWithdrawalService $withdrawals,
        private readonly WalletAdminService $admin,
        private readonly WalletSettingsService $settings,
        private readonly WalletLedgerAccessService $ledgerAccess,
        private readonly UserRepository $users,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $organizationId = (int) $actingUser['organization_id'];

        return $this->viewWithLayout('wallet/admin', 'layout', [
            'pendingWithdrawals' => $this->withdrawals->pendingForOrganization($actingUser),
            'transactions' => $this->ledgerAccess->forOrganization($actingUser, 100),
            'orgSettings' => $this->settings->orgSettings($organizationId),
            'platformLimits' => $this->settings->effectiveTopupLimits($organizationId),
            'users' => $this->users->findAllInOrganization($organizationId),
        ]);
    }

    public function approveWithdrawal(Request $request): Response
    {
        return $this->resolveWithdrawal($request, 'approve');
    }

    public function rejectWithdrawal(Request $request): Response
    {
        return $this->resolveWithdrawal($request, 'reject');
    }

    public function markWithdrawalPaid(Request $request): Response
    {
        return $this->resolveWithdrawal($request, 'markPaid');
    }

    private function resolveWithdrawal(Request $request, string $action): Response
    {
        $id = (int) $request->attribute('id');
        $note = (string) $request->input('note', '');

        try {
            $this->withdrawals->{$action}(CurrentUser::user(), $id, $note !== '' ? $note : null);
            Session::setFlash('success', 'Withdrawal request updated.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update request.');
        }

        return $this->redirect('/org/wallet');
    }

    public function adjust(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $direction = (string) $request->input('direction', 'credit');
        $currencyId = $this->settings->effectiveTopupLimits((int) $actingUser['organization_id'])['currency_id'];

        try {
            if ($direction === 'debit') {
                $this->admin->debitUser(
                    $actingUser,
                    (int) $request->input('user_id', 0),
                    $currencyId,
                    (string) $request->input('amount', ''),
                    (string) $request->input('reason', '')
                );
            } else {
                $this->admin->creditUser(
                    $actingUser,
                    (int) $request->input('user_id', 0),
                    $currencyId,
                    (string) $request->input('amount', ''),
                    (string) $request->input('reason', '')
                );
            }
            Session::setFlash('success', 'Wallet adjusted.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not adjust wallet.');
        }

        return $this->redirect('/org/wallet');
    }

    public function updateSettings(Request $request): Response
    {
        $actingUser = CurrentUser::user();

        try {
            $this->settings->updateOrgSettings($actingUser, (int) $actingUser['organization_id'], [
                'min_topup' => (string) $request->input('min_topup', '0'),
                'max_topup' => (string) $request->input('max_topup', '0'),
                'min_withdrawal' => (string) $request->input('min_withdrawal', '0'),
                'max_withdrawal' => (string) $request->input('max_withdrawal', '0'),
                'transfer_fee_fixed' => (string) $request->input('transfer_fee_fixed', '0'),
                'transfer_fee_percent' => (string) $request->input('transfer_fee_percent', '0'),
            ]);
            Session::setFlash('success', 'Wallet settings updated.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update settings.');
        }

        return $this->redirect('/org/wallet');
    }
}
