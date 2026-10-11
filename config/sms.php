<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    // The platform's own SMS account — used by organizations that keep
    // the default "our SMS service" (paid from their SMS credit). Which
    // provider it is, and its credentials, keyed by the provider's
    // credential field names (see the provider definitions registered in
    // Application). More providers (other countries) = more entries.
    'platform' => [
        'provider' => env('SMS_PLATFORM_PROVIDER', 'kavenegar'),
        'credentials' => [
            'kavenegar' => [
                // Kavenegar panel → API key. Empty = platform SMS disabled.
                'api_key' => env('KAVENEGAR_API_KEY', ''),
                // Required by Kavenegar: the line for Iranian numbers.
                'sender' => env('KAVENEGAR_SENDER', ''),
                // Separate line for international numbers (optional).
                'sender_international' => env('KAVENEGAR_SENDER_INTERNATIONAL', ''),
            ],
        ],
    ],
];
