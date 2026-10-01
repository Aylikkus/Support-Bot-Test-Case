<?php

// Overrides the SDK defaults (vendor/irazasyed/telegram-bot-sdk/src/Laravel/config/telegram.php).
// Updates are received via long polling (`php artisan telegram:poll`), so no webhook settings are needed.
return [
    'bots' => [
        'mybot' => [
            'token' => env('TELEGRAM_BOT_TOKEN', ''),
            'commands' => [],
        ],
    ],

    'default' => 'mybot',

    // No command classes: unknown /commands must not fall back to the SDK's HelpCommand.
    'commands' => [],
];
