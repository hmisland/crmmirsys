<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\SmsService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Super Admin: SMS provider status (Kavenegar), remaining account
 * credit, a test send, and the log of every SMS the app sent.
 */
final class SmsAdminController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly SmsService $sms)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        $credentials = $this->sms->platformCredentials();

        return $this->viewWithLayout('admin/sms', 'layout', [
            'configured' => $this->sms->isConfigured(),
            'provider' => $this->sms->platformProvider(),
            'sender' => (string) ($credentials['sender'] ?? ''),
            'senderInternational' => (string) ($credentials['sender_international'] ?? ''),
            'account' => $this->sms->isConfigured() ? $this->sms->accountInfo() : null,
            'logs' => $this->sms->recentLog(100),
        ]);
    }

    public function test(Request $request): Response
    {
        $this->requireSuperAdmin();

        $receptor = (string) $request->input('receptor', '');
        $template = trim((string) $request->input('template', ''));

        $result = $template !== ''
            ? $this->sms->sendTemplate($receptor, $template, [trim((string) $request->input('token', '')) ?: '12345'], 'admin.test')
            : $this->sms->send($receptor, (string) $request->input('message', ''), 'admin.test');

        Session::setFlash(
            $result->success ? 'success' : 'error',
            $result->success
                ? t('sms.test_sent')
                : t('sms.test_failed', ['error' => (string) $result->errorMessage . ($result->statusCode !== null ? ' (' . $result->statusCode . ')' : '')])
        );

        return $this->redirect('/admin/sms');
    }

    private function requireSuperAdmin(): void
    {
        $user = CurrentUser::user();

        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }
    }
}
