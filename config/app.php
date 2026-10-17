<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    'name' => env('APP_NAME', 'CRM Platform'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    // Storage zone of every system timestamp: always UTC (docs/organization-timezone-design.md).
    // APP_TIMEZONE is no longer read — an organization's own zone comes from
    // organizations.default_timezone through App\Infrastructure\Time\OrganizationTimezone.
    'timezone' => 'UTC',
    // Time zone for new organizations, and for screens without an organization (IANA id).
    'organization_timezone' => env('ORGANIZATION_TIMEZONE', 'Asia/Tehran'),
    'default_language' => env('APP_DEFAULT_LANGUAGE', 'fa'), // Persian is the platform default per Phase 0 §21/§49
    'theme' => env('APP_THEME', 'default'),
    'log_level' => env('LOG_LEVEL', 'debug'),
    // See routes/web.php's /system/migrate route + docs/phase2-authentication.md
    // for why this exists (no SSH on shared hosting to run a migration CLI).
    'migrate_token' => env('MIGRATE_TOKEN', ''),
];
