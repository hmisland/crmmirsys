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
];
