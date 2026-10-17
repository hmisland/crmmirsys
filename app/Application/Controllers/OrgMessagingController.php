<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\EmailProviderService;
use App\Application\Services\SmsService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Messaging\MessagingSettingsCards;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\e;
use function App\Infrastructure\Theme\t;

/**
 * Organization settings → Messaging providers: the one place where the
 * organization's message providers are configured.
 *
 *  - SMS — Kavenegar: the existing /org/sms settings (platform or own
 *    account), summarized here and edited on that page, unchanged.
 *  - Email — Sender and Email — Brevo: the organization's own API key.
 *
 * Every card is always shown. The email cards are usable only when the
 * organization has the "marketing_email" module; otherwise they are
 * muted and every POST below refuses — the check is here, server-side,
 * not only in the template. Routes need organization.edit.
 */
final class OrgMessagingController extends BaseController
{
    public const EMAIL_MODULE = 'marketing_email';
    public const MARKETING_MODULE = 'marketing';

    public function __construct(
        TemplateEngine $templates,
        private readonly SmsService $sms,
        private readonly EmailProviderService $email,
        private readonly ModuleGate $gate,
        private readonly ActionRateLimiter $limiter = new ActionRateLimiter(),
        private readonly ?MessagingSettingsCards $extraCards = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->organizationId();
        $user = CurrentUser::user();
        $smsSettings = $this->sms->organizationSettings($organizationId);

        return $this->viewWithLayout('org/messaging', 'layout', [
            'sms' => [
                'available' => $this->sms->providersFor($organizationId) !== [],
                'mode' => $smsSettings['mode'],
                'provider' => $smsSettings['provider'],
                'can_send' => $this->sms->canSend(),
            ],
            'smsMarketingEntitled' => $this->gate->allows($user, self::MARKETING_MODULE, null),
            'emailEntitled' => $this->gate->allows($user, self::EMAIL_MODULE, null),
            'definitions' => $this->email->definitions(),
            'emailSettings' => $this->email->settings(),
            // Cards added by modules (e.g. Bale), each with its own server-side checks.
            'extraCards' => $this->extraCards?->render((array) $user) ?? [],
        ]);
    }

    public function saveEmail(Request $request): Response
    {
        $provider = $this->entitledProvider($request);
        if ($provider === null) {
            return $this->redirect('/org/messaging');
        }
        $outcome = $this->email->save($provider, [
            'api_secret' => (string) $request->input('api_secret', ''),
            'sender_email' => (string) $request->input('sender_email', ''),
            'sender_name' => (string) $request->input('sender_name', ''),
            'reply_to' => (string) $request->input('reply_to', ''),
        ], (int) CurrentUser::user()['id']);

        if ($outcome['error'] !== null) {
            Session::setFlash('error', $this->message($outcome['error']));
        } else {
            Session::setFlash('success', t('email_providers.saved') . $this->warnings($outcome['result']?->warnings ?? []));
        }

        return $this->back($provider);
    }

    public function testConnection(Request $request): Response
    {
        $provider = $this->entitledProvider($request);
        if ($provider === null) {
            return $this->redirect('/org/messaging');
        }
        $result = $this->email->test($provider, (int) CurrentUser::user()['id']);

        Session::setFlash(
            $result->ok ? 'success' : 'error',
            $result->ok ? t('email_providers.test_ok') . $this->warnings($result->warnings) : $this->message((string) $result->error)
        );

        return $this->back($provider);
    }

    public function sendTestEmail(Request $request): Response
    {
        $provider = $this->entitledProvider($request);
        if ($provider === null) {
            return $this->redirect('/org/messaging');
        }

        if (!$this->limiter->allowTestSend(CurrentUser::user())) {
            Session::setFlash('error', t('email_providers.error_test_rate_limited'));

            return $this->back($provider);
        }

        $result = $this->email->sendTest(
            $provider,
            trim((string) $request->input('to', '')),
            t('email_providers.test_subject'),
            '<p>' . e(t('email_providers.test_body')) . '</p>',
            (int) CurrentUser::user()['id']
        );

        Session::setFlash(
            $result->success ? 'success' : 'error',
            $result->success
                ? t('email_providers.test_email_sent', ['id' => (string) ($result->messageId ?? '—')])
                : $this->message((string) $result->errorMessage)
        );

        return $this->back($provider);
    }

    public function disconnect(Request $request): Response
    {
        $provider = $this->entitledProvider($request);
        if ($provider === null) {
            return $this->redirect('/org/messaging');
        }
        $this->email->disconnect($provider, (int) CurrentUser::user()['id']);
        Session::setFlash('success', t('email_providers.disconnected'));

        return $this->back($provider);
    }

    public function registerWebhook(Request $request): Response
    {
        $provider = $this->entitledProvider($request);
        if ($provider === null) {
            return $this->redirect('/org/messaging');
        }
        $error = $this->email->registerWebhook($provider, (int) CurrentUser::user()['id']);

        Session::setFlash($error === null ? 'success' : 'error', $error === null ? t('email_providers.webhook_registered') : $this->message($error));

        return $this->back($provider);
    }

    /**
     * The provider from the URL, only when it exists and the
     * organization holds the email module. Otherwise nothing happens.
     */
    private function entitledProvider(Request $request): ?string
    {
        $this->organizationId();
        $provider = (string) $request->attribute('provider', '');
        if ($this->email->definition($provider) === null) {
            throw new NotFoundException();
        }

        if (!$this->gate->allows(CurrentUser::user(), self::EMAIL_MODULE, null)) {
            Session::setFlash('error', t('email_providers.module_unavailable'));

            return null;
        }

        return $provider;
    }

    private function message(string $error): string
    {
        return str_starts_with($error, 'email_providers.') ? t($error) : t('email_providers.provider_error', ['error' => $error]);
    }

    /** @param string[] $warnings */
    private function warnings(array $warnings): string
    {
        return $warnings === [] ? '' : ' ' . implode(' ', array_map(fn ($w) => t($w), $warnings));
    }

    private function back(string $provider): Response
    {
        return $this->redirect('/org/messaging#provider-' . $provider);
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
