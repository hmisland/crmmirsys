<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    // Comma-separated exact origins allowed to call /api/* from a browser,
    // e.g. "https://app.example.com". Empty (default) = CORS disabled: no
    // CORS headers are sent. A wildcard is never honoured. Not needed for
    // native apps, nor for a PWA served from the CRM's own origin.
    'allowed_origins' => (string) env('CORS_ALLOWED_ORIGINS', ''),
];
