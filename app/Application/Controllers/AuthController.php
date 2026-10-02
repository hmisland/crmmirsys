<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AuthService;
use App\Application\Services\BankAccountService;
use App\Application\Services\BankService;
use App\Core\Container;
use App\Core\Exceptions\AuthenticationException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Per Phase 0 §55: request/response plumbing only — every actual
 * decision (throttling, password checks, token validity) lives in
 * AuthService. Errors from the Service are caught here just to turn
 * them back into the right form-with-error-message view instead of
 * the generic error page, which would be a poor UX for a login form.
 */
final class AuthController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly AuthService $auth,
        private readonly Container $container,
        private readonly BankAccountService $bankAccounts,
        private readonly BankService $banks,
    ) {
        parent::__construct($templates);
    }

    public function showLogin(Request $request): Response
    {
        if (!CurrentUser::isGuest()) {
            return $this->redirect('/');
        }

        return $this->viewWithLayout('auth/login', 'layout', ['error' => null, 'old' => []]);
    }

    public function login(Request $request): Response
    {
        try {
            $this->auth->login(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) $request->server('REMOTE_ADDR', '0.0.0.0')
            );
        } catch (AuthenticationException|HttpException $e) {
            return $this->viewWithLayout('auth/login', 'layout', [
                'error' => $e->getMessage(),
                'old' => ['email' => $request->input('email', '')],
            ], $e instanceof HttpException ? $e->statusCode : 401);
        }

        return $this->redirect('/');
    }

    public function showRegister(Request $request): Response
    {
        if (!CurrentUser::isGuest()) {
            return $this->redirect('/');
        }

        return $this->viewWithLayout('auth/register', 'layout', ['errors' => [], 'old' => []]);
    }

    public function register(Request $request): Response
    {
        try {
            $this->auth->register($request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('auth/register', 'layout', [
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Account created. Please check your email to verify your address before logging in.');

        return $this->redirect('/login');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return $this->redirect('/login');
    }

    public function verifyEmail(Request $request): Response
    {
        $token = (string) $request->attribute('token', '');

        try {
            $this->auth->verifyEmail($token);
            $status = 'success';
            $message = 'Your email is verified. You can log in now.';
        } catch (HttpException $e) {
            $status = 'error';
            $message = $e->getMessage();
        }

        return $this->viewWithLayout('auth/verify-result', 'layout', ['status' => $status, 'message' => $message]);
    }

    public function showForgotPassword(Request $request): Response
    {
        return $this->viewWithLayout('auth/forgot-password', 'layout', ['sent' => false]);
    }

    public function forgotPassword(Request $request): Response
    {
        // Always the same response, whether or not the email exists —
        // see AuthService::requestPasswordReset()'s docblock.
        $this->auth->requestPasswordReset((string) $request->input('email', ''));

        return $this->viewWithLayout('auth/forgot-password', 'layout', ['sent' => true]);
    }

    public function showResetPassword(Request $request): Response
    {
        return $this->viewWithLayout('auth/reset-password', 'layout', [
            'token' => (string) $request->attribute('token', ''),
            'errors' => [],
        ]);
    }

    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->attribute('token', '');

        try {
            $this->auth->resetPassword(
                $token,
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', '')
            );
        } catch (ValidationException $e) {
            return $this->viewWithLayout('auth/reset-password', 'layout', ['token' => $token, 'errors' => $e->errors], 422);
        } catch (HttpException $e) {
            return $this->viewWithLayout('auth/reset-password', 'layout', ['token' => $token, 'errors' => ['token' => [$e->getMessage()]]], $e->statusCode);
        }

        Session::setFlash('success', 'Your password has been reset. Please log in.');

        return $this->redirect('/login');
    }

    /**
     * Requires AuthMiddleware on its route — see routes/web.php.
     */
    public function profile(Request $request): Response
    {
        return $this->viewWithLayout('auth/profile', 'layout', [
            'user' => CurrentUser::user(),
            'errors' => [],
            'notificationChannels' => $this->notificationChannelData(),
            'bankAccounts' => $this->bankAccountsForCurrentUser(),
            'banks' => $this->banks->all(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        try {
            $this->auth->updateProfile((int) CurrentUser::id(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('auth/profile', 'layout', [
                'user' => CurrentUser::user(),
                'errors' => $e->errors,
                'notificationChannels' => $this->notificationChannelData(),
                'bankAccounts' => $this->bankAccountsForCurrentUser(),
                'banks' => $this->banks->all(),
            ], 422);
        }

        Session::setFlash('success', 'Profile updated.');

        return $this->redirect('/profile');
    }

    /**
     * Bank accounts and Notification channels are both stored in
     * TenantScopedRepository tables (organization_id matters — a
     * bank account or Bale chat ID has to belong to some org). Super
     * Admin has no organization at all, so these queries have no
     * tenant to scope by — visiting their own /profile threw
     * "No OrganizationContext is set" before this guard existed (a
     * real bug, caught from a live server log, not found in review).
     * Both sections simply have nothing to show for Super Admin —
     * they have no org-scoped wallet to withdraw from either.
     */
    private function bankAccountsForCurrentUser(): array
    {
        $user = CurrentUser::user();

        if ($user === null || $user['organization_id'] === null) {
            return [];
        }

        return $this->bankAccounts->forUser((int) $user['id']);
    }

    /**
     * Null when the Notification module isn't installed/loaded at all
     * — the profile page simply skips that section then. This is the
     * same lazy, non-hard-dependency pattern Internal Mail uses to
     * optionally trigger a notification (see Application.php's module
     * bootstrap): Core (Profile) never directly references the
     * module's classes by name outside this one soft-checked spot.
     */
    private function notificationChannelData(): ?array
    {
        $user = CurrentUser::user();

        if ($user === null || $user['organization_id'] === null) {
            return null;
        }

        $serviceClass = 'Modules\\Notification\\Services\\NotificationRecipientService';

        if (!$this->container->has($serviceClass)) {
            return null;
        }

        $service = $this->container->make($serviceClass);

        return $service->forUser((int) $user['id']);
    }
}
