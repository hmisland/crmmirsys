<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    // 'log' (default, safe — writes to storage/mail/, sends nothing),
    // 'php_mail' (host's local sendmail, no credentials), or
    // 'smtp' (real SMTP with AUTH LOGIN — use this for a real mailbox
    // like one created in cPanel's Email Accounts).
    'driver' => env('MAIL_DRIVER', 'log'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
    'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'CRM Platform')),

    'smtp' => [
        'host' => env('MAIL_SMTP_HOST', 'localhost'),
        'port' => (int) env('MAIL_SMTP_PORT', 465),
        'username' => env('MAIL_SMTP_USERNAME', ''),
        'password' => env('MAIL_SMTP_PASSWORD', ''),
        // 'ssl' (implicit TLS, typically port 465), 'tls' (STARTTLS,
        // typically port 587), or 'none'.
        'encryption' => env('MAIL_SMTP_ENCRYPTION', 'ssl'),
    ],
];
