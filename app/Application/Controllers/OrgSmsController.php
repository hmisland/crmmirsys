<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\SmsService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Organization settings → SMS. The org admin chooses how the
 * organization's SMS are sent:
 *  - platform (default): our provider account, paid per SMS from the
 *    organization's prepaid credit (charged from Wallet);
 *  - own: the organization's own account at a provider that serves its
 *    language (Kavenegar for Persian) — they top it up themselves.
 * The providers offered and their fields come from SmsProviderRegistry.
 * Routes are behind organization.edit.
 */
final class OrgSmsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly SmsService $sms,
        private readonly ActionRateLimiter $limiter = new ActionRateLimiter(),
    )
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->organizationId();
        $settings = $this->sms->organizationSettings($organizationId);
        $billing = $this->sms->billing();
        $providers = $this->sms->providersFor($organizationId);
        $platformAvailable = $this->sms->platformAvailableFor($organizationId);

        return $this->viewWithLayout('org/sms', 'layout', [
            'settings' => $settings,
            'providers' => $providers,
            'platformAvailable' => $platformAvailable,
            'platformReady' => $platformAvailable && $this->sms->isConfigured(),
            'billingReady' => $billing !== null,
            'unitPrice' => $billing?->unitPrice(),
            'balance' => $billing?->balance($organizationId),
            'ownAccount' => $settings['mode'] === SmsService::MODE_OWN ? $this->sms->accountInfoFor($organizationId) : null,
            'logs' => $providers !== [] ? $this->sms->recentLog(20, $organizationId) : [],
        ]);
    }

    public function save(Request $request): Response
    {
        $user = CurrentUser::user();
        $organizationId = $this->organizationId();
        $mode = (string) $request->input('mode', SmsService::MODE_PLATFORM) === SmsService::MODE_OWN ? SmsService::MODE_OWN : SmsService::MODE_PLATFORM;
        $provider = (string) $request->input('provider', '');

        $error = $this->sms->saveOrganizationSettings(
            $organizationId,
            $mode,
            $provider,
            (array) (((array) $request->input('fields', []))[$provider] ?? []),
            (int) $user['id']
        );

        if ($error !== null) {
            $message = str_starts_with($error, 'org_sms.') ? t($error) : t('org_sms.error_key_rejected', ['error' => $error]);
            Session::setFlash('error', $message);
        } else {
            Session::setFlash('success', t('org_sms.saved'));
        }

        return $this->redirect('/org/sms');
    }

    public function test(Request $request): Response
    {
        $this->organizationId();
        // Test SMS are real (and billed in platform mode): shared limit, config messaging.test_sends.
        if (!$this->limiter->allowTestSend(CurrentUser::user())) {
            Session::setFlash('error', t('email_providers.error_test_rate_limited'));

            return $this->redirect('/org/sms');
        }
        $result = $this->sms->send((string) $request->input('receptor', ''), t('org_sms.test_text'), 'org.test');

        Session::setFlash(
            $result->success ? 'success' : 'error',
            $result->success ? t('sms.test_sent') : t('sms.test_failed', ['error' => (string) $result->errorMessage])
        );

        return $this->redirect('/org/sms');
    }

    /** Organization-level page: users without an organization (Super Admin) get a 404. */
    private function organizationId(): int
    {
        $organizationId = (int) (CurrentUser::user()['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw new NotFoundException();
        }

        return $organizationId;
    }
}
