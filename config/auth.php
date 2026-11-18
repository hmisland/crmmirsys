<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    'require_email_verification' => (bool) env('AUTH_REQUIRE_EMAIL_VERIFICATION', true),
    'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 8),
    'email_verification_ttl_minutes' => (int) env('AUTH_EMAIL_VERIFICATION_TTL_MINUTES', 60 * 24),
    'password_reset_ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TTL_MINUTES', 60),
    'login_max_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
    'login_throttle_minutes' => (int) env('AUTH_LOGIN_THROTTLE_MINUTES', 15),
        'captcha_enabled' => (bool) env('AUTH_CAPTCHA_ENABLED', true),
    'captcha_ttl_seconds' => (int) env('AUTH_CAPTCHA_TTL_SECONDS', 600),

    // Bearer access tokens for non-browser clients (migration 0094).
    // Absolute lifetime; there is no refresh token yet (docs/phase0-multi-client-api.md).
    'api_token_ttl_days' => (int) env('AUTH_API_TOKEN_TTL_DAYS', 30),
    // Active tokens (devices) per user; issuing one more revokes the oldest.
    'api_token_max_per_user' => (int) env('AUTH_API_TOKEN_MAX_PER_USER', 10),
    // POST /api/v1/auth/token requests allowed per client IP per window.
    'api_token_issue_max_per_ip' => (int) env('AUTH_API_TOKEN_ISSUE_MAX_PER_IP', 20),
    'api_token_issue_window_seconds' => (int) env('AUTH_API_TOKEN_ISSUE_WINDOW_SECONDS', 600),
];
