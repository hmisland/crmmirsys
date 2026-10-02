<?php

declare(strict_types=1);

use function App\Infrastructure\Config\env;

return [
    'bale' => [
        // Get this from @botfather in Bale when you create your bot.
        // Empty until set — BaleGateway::isConfigured() returns false
        // and every send logs "Bale bot token is not configured"
        // rather than crashing.
        'bot_token' => env('BALE_BOT_TOKEN', ''),
    ],
];
