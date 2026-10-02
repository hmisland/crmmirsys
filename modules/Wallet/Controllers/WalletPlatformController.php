<?php

declare(strict_types=1);

namespace Modules\Wallet\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\BankService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Wallet\Services\WalletSettingsService;

/**
 * Bank name management lives here (not a separate page) per an
 * explicit request: "مدیریت افزودن اسم بانک و در مدیریت ولت سوپر ادمین
 * قرار بده" — Super Admin's platform wallet settings page is where
 * every platform-wide financial setting already lives (top-up limits,
 * currency), so the list of banks a user can pick from when saving a
 * withdrawal account belongs right alongside them. `BankService`
 * itself is a Core service (banks aren't Wallet-specific data, the
 * same reasoning that put bank accounts on Core's Profile page), a
 * module depending on Core here is the normal direction.
 */
final class WalletPlatformController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly WalletSettingsService $settings,
        private readonly CurrencyRepository $currencies,
        private readonly BankService $banks,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        return $this->viewWithLayout('wallet/platform-settings', 'layout', [
            'settings' => $this->settings->platformSettings(),
            'currencies' => $this->currencies->all(),
            'banks' => $this->banks->all(),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireSuperAdmin();

        try {
            $this->settings->updatePlatformSettings(
                CurrentUser::user(),
                (int) $request->input('currency_id', 0),
                (string) $request->input('min_topup', '0'),
                (string) $request->input('max_topup', '0')
            );
            Session::setFlash('success', 'Platform wallet settings updated.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update settings.');
        }

        return $this->redirect('/admin/wallet/settings');
    }

    public function addBank(Request $request): Response
    {
        $this->requireSuperAdmin();

        try {
            $this->banks->add(CurrentUser::user(), (string) $request->input('name', ''));
            Session::setFlash('success', 'Bank added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add bank.');
        }

        return $this->redirect('/admin/wallet/settings');
    }

    public function deactivateBank(Request $request): Response
    {
        $this->requireSuperAdmin();

        $this->banks->deactivate(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Bank removed from the list.');

        return $this->redirect('/admin/wallet/settings');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
