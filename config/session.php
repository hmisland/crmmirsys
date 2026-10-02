<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    'cookie_name' => env('SESSION_COOKIE_NAME', 'crm_session'),
    'lifetime_minutes' => (int) env('SESSION_LIFETIME_MINUTES', 120),
    'secure' => (bool) env('SESSION_SECURE_COOKIE', true), // set false only for local http-only dev
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'domain' => env('SESSION_DOMAIN', ''),
];
