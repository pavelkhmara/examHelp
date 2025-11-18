<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for server monitoring and alerting system.
    |
    */

    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Telegram Notifications
    |--------------------------------------------------------------------------
    |
    | Configure Telegram bot for server error notifications.
    | To create a bot: message @BotFather on Telegram and use /newbot command.
    |
    */

    'telegram' => [
        'enabled' => env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Settings
    |--------------------------------------------------------------------------
    |
    | Configure which errors trigger alerts and alert behavior.
    |
    */

    'alerts' => [
        // Send alerts for 500 errors
        'server_errors' => env('MONITORING_ALERT_SERVER_ERRORS', true),

        // Minimum error level to trigger alert (emergency, alert, critical, error, warning)
        'min_level' => env('MONITORING_ALERT_MIN_LEVEL', 'error'),

        // Rate limit: minutes between alerts for same error type
        'rate_limit_minutes' => env('MONITORING_ALERT_RATE_LIMIT', 5),

        // Ignore specific exception types (comma-separated class names)
        'ignored_exceptions' => array_filter(
            explode(',', env('MONITORING_IGNORED_EXCEPTIONS', ''))
        ),
    ],
];
