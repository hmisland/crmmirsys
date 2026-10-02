<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Application\Services\AuthService;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;

/**
 * Reuses the same cookie-based PHP session as the web app rather than
 * introducing a separate token scheme in Phase 2 — Phase 0 §28 asks
 * for a consistent envelope and independence from HTML templates,
 * not a specific auth transport. A proper API token mechanism
 * (personal access tokens, similar to Sanctum) can be added later
 * as an additional, opt-in way to call this same API without
 * replacing session auth for the web app — noted here as an open
 * item rather than decided silently.
 */
final class AuthApiController extends BaseApiController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request): Response
    {
        $user = $this->auth->register($request->all());

        return $this->created($this->sanitize($user));
    }

    public function login(Request $request): Response
    {
        $user = $this->auth->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->server('REMOTE_ADDR', '0.0.0.0')
        );

        return $this->data($this->sanitize($user));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return $this->noContent();
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
