<?php

declare(strict_types=1);

namespace Modules\Wallet\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Wallet\Services\WalletGiftService;

use function App\Infrastructure\Theme\t;

/**
 * Gift credit: Super Admin code generation (/admin/wallet/gift-codes),
 * org admin redeem + send-to-colleague (forms on /org/wallet).
 */
final class WalletGiftController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly WalletGiftService $gifts,
        private readonly OrganizationRepository $organizations,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $codes = $this->gifts->list($user);

        // Codes just generated are shown once, prominently, for copying.
        $newCodes = (array) json_decode((string) ($_SESSION['_gift_codes'] ?? '[]'), true);
        unset($_SESSION['_gift_codes']);

        return $this->viewWithLayout('wallet/gift-codes', 'layout', [
            'codes' => $codes,
            'organizations' => $this->organizations->all(),
            'newCodes' => $newCodes,
        ]);
    }

    public function generate(Request $request): Response
    {
        try {
            $codes = $this->gifts->generate(
                CurrentUser::user(),
                (string) $request->input('amount', ''),
                (int) $request->input('count', 1),
                (int) $request->input('organization_id', 0) ?: null,
                ((string) $request->input('expires_at', '')) ?: null,
                (string) $request->input('note', '')
            );
            // Show the new codes once on the next page.
            $_SESSION['_gift_codes'] = json_encode($codes);
            Session::setFlash('success', t('wallet_gift.generated', ['count' => count($codes)]));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'wallet_gift.error_amount')));
        }

        return $this->redirect('/admin/wallet/gift-codes');
    }

    public function revoke(Request $request): Response
    {
        $done = $this->gifts->revoke(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash($done ? 'success' : 'error', t($done ? 'wallet_gift.revoked' : 'wallet_gift.error_not_revocable'));

        return $this->redirect('/admin/wallet/gift-codes');
    }

    public function redeem(Request $request): Response
    {
        try {
            $result = $this->gifts->redeem(CurrentUser::user(), (string) $request->input('code', ''));
            Session::setFlash('success', t('wallet_gift.redeemed', ['amount' => number_format((float) $result['amount'])]));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'wallet_gift.error_not_found')));
        }

        // The form exists on both /wallet and /org/wallet; go back to whichever was used.
        return $this->redirect($request->input('return') === '/wallet' ? '/wallet' : '/org/wallet');
    }

    public function send(Request $request): Response
    {
        try {
            $this->gifts->sendToColleague(
                CurrentUser::user(),
                (int) $request->input('recipient_user_id', 0),
                (string) $request->input('amount', ''),
                (string) $request->input('note', '')
            );
            Session::setFlash('success', t('wallet_gift.sent'));
        } catch (ValidationException $e) {
            $message = (string) (array_values($e->errors)[0][0] ?? '');
            $message = match ($message) {
                'Insufficient balance.' => 'wallet_gift.error_insufficient',
                'This wallet is frozen.' => 'wallet_gift.error_frozen',
                default => $message,
            };
            Session::setFlash('error', str_starts_with($message, 'wallet_gift.') ? t($message) : t('wallet_gift.error_send', ['error' => $message]));
        }

        return $this->redirect('/org/wallet');
    }
}
