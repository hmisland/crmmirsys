<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
        'sandbox' => (bool) env('ZARINPAL_SANDBOX', true),
    ],
];
