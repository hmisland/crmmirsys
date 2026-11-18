<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Application\Services\ApiTokenService;
use App\Application\Services\AuthService;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Security\CurrentUser;

/**
 * Bearer-token endpoints for non-browser clients (Android, future iOS).
 *
 *   POST   /api/v1/auth/token   credentials + device_name  -> new access token
 *   DELETE /api/v1/auth/token   revokes the token used for this very request
 *
 * Browsers/PWA keep using the cookie session; see docs/phase0-multi-client-api.md.
 * Issuance never creates a PHP session (Application treats it as stateless).
 */
final class TokenApiController extends BaseApiController
{
    private const RATE_BUCKET = 'auth.token_issue';

    public function __construct(
        private readonly AuthService $auth,
        private readonly ApiTokenService $tokens,
        private readonly ActionRateLimiter $limiter,
    ) {
    }

    public function issue(Request $request): Response
    {
        $ip = (string) $request->server('REMOTE_ADDR', '0.0.0.0');

        // Per-IP cap on token requests (shared rate_limit_hits storage). The
        // per-account brute-force lockout lives in AuthService (LoginThrottle).
        $allowed = $this->limiter->attempt(self::RATE_BUCKET, [[
            'subject' => 'ip:' . $ip,
            'max' => (int) Config::get('auth.api_token_issue_max_per_ip', 20),
            'window' => (int) Config::get('auth.api_token_issue_window_seconds', 600),
        ]]);

        if (!$allowed) {
            throw new HttpException(429, 'Too many token requests. Please try again later.', 'too_many_requests');
        }

        $email = $request->input('email');
        $password = $request->input('password');
        $errors = [];

        if (!is_string($email) || trim($email) === '') {
            $errors['email'] = ['This field is required.'];
        }

        if (!is_string($password) || $password === '') {
            $errors['password'] = ['This field is required.'];
        }

        $deviceName = '';
        try {
            $deviceName = ApiTokenService::normalizeDeviceName($request->input('device_name'));
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $user = $this->auth->loginForApiToken((string) $email, (string) $password, $ip);

        $issued = $this->tokens->issue(
            $user,
            $deviceName,
            $ip,
            (string) $request->header('USER-AGENT', '')
        );

        unset($user['password_hash']);

        return $this->created([
            'token_type' => $issued['token_type'],
            'access_token' => $issued['access_token'],
            'expires_at' => $issued['expires_at'],
            'expires_in' => $issued['expires_in'],
            'token_id' => $issued['token_id'],
            'device_name' => $issued['device_name'],
            'user' => $user,
        ])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    public function revoke(Request $request): Response
    {
        $tokenId = $request->attribute('api_token_id');

        if ($request->attribute('auth_method') !== 'bearer' || !is_int($tokenId)) {
            throw new HttpException(400, 'This endpoint revokes the Bearer token used to call it.', 'bearer_required');
        }

        $this->tokens->revoke($tokenId, (int) CurrentUser::id());

        return $this->noContent();
    }
}
