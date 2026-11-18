<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Application\Services\ApiTokenService;
use App\Application\Services\AuthService;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\Csrf;
use App\Infrastructure\Security\CurrentUser;

/**
 * Two ways to call this API, one business layer behind both:
 *  - browsers/PWA: the same cookie-based PHP session as the web app
 *    (CSRF-protected writes);
 *  - native clients: a revocable Bearer access token from
 *    POST /api/v1/auth/token (stateless, see TokenApiController and
 *    docs/phase0-multi-client-api.md).
 * Both resolve to the same CurrentUser, so tenant isolation and RBAC apply
 * identically.
 */
final class AuthApiController extends BaseApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ApiTokenService $tokens,
    ) {
    }

    public function register(Request $request): Response
    {
        $user = $this->auth->register($request->all());

        return $this->created($this->sanitize($user));
    }

    public function login(Request $request): Response
    {
        // Cookie-session login. A Bearer client has no session (Application
        // does not start one for it) and must use POST /api/v1/auth/token.
        if ($request->attribute('auth_method') === 'bearer') {
            throw new HttpException(400, 'Bearer clients obtain tokens from POST /api/v1/auth/token.', 'use_token_endpoint');
        }

        $user = $this->auth->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->server('REMOTE_ADDR', '0.0.0.0')
        );

        return $this->data($this->sanitize($user));
    }

    public function logout(Request $request): Response
    {
        // A Bearer request is stateless: logging out means revoking its token.
        // Calling AuthService::logout() here would start a session (and emit
        // a Set-Cookie) just to destroy it.
        if ($request->attribute('auth_method') === 'bearer') {
            $this->tokens->revoke((int) $request->attribute('api_token_id'), (int) CurrentUser::id());

            return $this->noContent();
        }

        $this->auth->logout();

        return $this->noContent();
    }

    /**
     * CSRF token of the current browser session, for fetch/XHR clients (PWA)
     * that cannot read it from a rendered form. Send it back in the
     * X-CSRF-Token header on POST/PUT/PATCH/DELETE. Not applicable to
     * Bearer-authenticated requests, which are not cookie-authenticated.
     */
    public function csrf(Request $request): Response
    {
        if ($request->attribute('auth_method') === 'bearer' || session_status() !== PHP_SESSION_ACTIVE) {
            throw new HttpException(400, 'CSRF tokens apply to cookie-session requests only.', 'csrf_not_applicable');
        }

        return $this->data(['csrf_token' => Csrf::token(), 'header' => 'X-CSRF-Token', 'field' => '_csrf'])
            ->withHeader('Cache-Control', 'no-store');
    }

    public function me(Request $request): Response
    {
        return $this->data($this->sanitize(CurrentUser::user()));
    }

    public function verifyEmail(Request $request): Response
    {
        $this->auth->verifyEmail((string) $request->input('token', ''));

        return $this->data(['verified' => true]);
    }

    public function forgotPassword(Request $request): Response
    {
        $this->auth->requestPasswordReset((string) $request->input('email', ''));

        // Always 200 with the same body regardless of whether the
        // email exists — see AuthService::requestPasswordReset().
        return $this->data(['message' => 'If that email is registered, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): Response
    {
        $this->auth->resetPassword(
            (string) $request->input('token', ''),
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', '')
        );

        return $this->data(['reset' => true]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->auth->updateProfile((int) CurrentUser::id(), $request->all());

        return $this->data($this->sanitize($user));
    }

    /**
     * Never let password_hash leave this controller, under any path.
     */
    private function sanitize(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        unset($user['password_hash']);

        return $user;
    }
}
