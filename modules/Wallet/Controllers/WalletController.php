<?php

declare(strict_types=1);

namespace Modules\Wallet\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\BankAccountService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Wallet\Gateways\GatewayRegistry;
use Modules\Wallet\Services\WalletGiftService;
use Modules\Wallet\Services\WalletService;
use Modules\Wallet\Services\WalletSettingsService;
use Modules\Wallet\Services\WalletTopUpService;
use Modules\Wallet\Services\WalletTransferService;
use Modules\Wallet\Services\WalletWithdrawalService;
use Modules\Wallet\WalletWithdrawalRepository;

use function App\Infrastructure\Theme\t;

final class WalletController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly WalletService $wallet,
        private readonly WalletTopUpService $topUp,
        private readonly WalletTransferService $transfer,
        private readonly WalletWithdrawalService $withdrawal,
        private readonly WalletSettingsService $settings,
        private readonly WalletWithdrawalRepository $withdrawalRequests,
        private readonly GatewayRegistry $gateways,
        private readonly UserRepository $users,
        private readonly BankAccountService $bankAccounts,
        private readonly ?WalletGiftService $gifts = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $organizationId = (int) $actingUser['organization_id'];
        $currencyId = $this->settings->effectiveTopupLimits($organizationId)['currency_id'];

        $wallet = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], $currencyId);

        $type = (string) $request->query('type', '');
        $ledger = array_filter($this->wallet->ledgerFor((int) $wallet['id'], 1000), fn ($e) => $type === '' || $e['type'] === $type);
        [$ledger, $pagination] = Pagination::slice($ledger, $request);

        return $this->viewWithLayout('wallet/index', 'layout', [
            'wallet' => $wallet,
            'ledger' => $ledger,
            'pagination' => $pagination,
            'ledgerType' => $type,
            'withdrawalRequests' => $this->withdrawalRequests->forWallet((int) $wallet['id']),
            'limits' => $this->settings->effectiveTopupLimits($organizationId),
            'gateways' => $this->gateways->all(),
            'colleagues' => array_filter(
                $this->users->findAllInOrganization($organizationId),
                fn ($u) => (int) $u['id'] !== (int) $actingUser['id']
            ),
            'bankAccounts' => $this->bankAccounts->forUser((int) $actingUser['id']),
            'transferFee' => $this->transfer->feeFor($organizationId),
            'canGift' => $this->gifts?->canManage($actingUser) ?? false,
        ]);
    }

    public function initiateTopUp(Request $request): Response
    {
        $gatewayKey = (string) $request->input('gateway', '');
        // The callback URL is always built server-side from the app's
        // own configured URL — never trusted from the request.
        $callbackUrl = $this->absoluteUrl('/wallet/callback/' . $gatewayKey);

        try {
            $result = $this->topUp->initiate(
                CurrentUser::user(),
                $gatewayKey,
                (string) $request->input('amount', ''),
                $callbackUrl
            );
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not start payment.');

            return $this->redirect('/wallet');
        }

        return $this->redirect($result['redirectUrl']);
    }

    public function callback(Request $request): Response
    {
        $gatewayKey = (string) $request->attribute('gateway');
        $transactionId = (int) $request->query('tx', '0');

        try {
            $result = $this->topUp->handleCallback($transactionId, $gatewayKey, $request->allQuery());
            Session::setFlash('success', $result['alreadyProcessed']
                ? 'This top-up was already processed.'
                : 'Wallet topped up successfully.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Payment could not be completed.');
        }

        return $this->redirect('/wallet');
    }

    public function transfer(Request $request): Response
    {
        try {
            $result = $this->transfer->transfer(
                CurrentUser::user(),
                (int) $request->input('recipient_user_id', 0),
                (string) $request->input('amount', '')
            );
            Session::setFlash('success', bccomp($result['fee'], '0', 18) > 0
                ? t('wallet.transfer_sent_fee', ['fee' => number_format((float) $result['fee'])])
                : t('wallet.transfer_sent'));
        } catch (ValidationException $e) {
            $message = array_values($e->errors)[0][0] ?? 'Could not complete transfer.';
            Session::setFlash('error', $message === 'Insufficient balance.' ? t('wallet.transfer_insufficient') : $message);
        }

        return $this->redirect('/wallet');
    }

    public function requestWithdrawal(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $organizationId = (int) $actingUser['organization_id'];
        $currencyId = $this->settings->effectiveTopupLimits($organizationId)['currency_id'];
        $wallet = $this->wallet->getOrCreateWallet($organizationId, (int) $actingUser['id'], $currencyId);

        try {
            $bankAccountId = (int) $request->input('bank_account_id', 0);
            if ($bankAccountId <= 0) {
                throw new ValidationException(['bank_account_id' => ['Choose which bank account to receive the money.']]);
            }

            // Ownership is verified here, not trusted from the form —
            // requireOwnAccount() throws if this account belongs to
            // someone else, same defense-in-depth as every other
            // record lookup in this app.
            $account = $this->bankAccounts->requireOwnAccount((int) $actingUser['id'], $bankAccountId);
            $destinationInfo = $this->bankAccounts->formatForWithdrawal($account);

            $this->withdrawal->request($actingUser, (int) $wallet['id'], (string) $request->input('amount', ''), $destinationInfo);
            Session::setFlash('success', 'Withdrawal requested — pending approval.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not request withdrawal.');
        } catch (NotFoundException $e) {
            Session::setFlash('error', 'That bank account was not found on your profile.');
        }

        return $this->redirect('/wallet');
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base . $path;
    }
}
