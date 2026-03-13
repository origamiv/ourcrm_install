<?php

return [

    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),

    /*
     * Whitelist of Telegram chat IDs that are allowed to interact with the bot.
     * Comma-separated string in env: TELEGRAM_ALLOWED_CHAT_IDS=123456789,987654321
     * Empty means no restriction (not recommended for production).
     */
    'allowed_chat_ids' => array_filter(
        array_map('trim', explode(',', env('TELEGRAM_ALLOWED_CHAT_IDS', '')))
    ),

    /*
     * Seconds to sleep between polling loop iterations.
     */
    'poll_interval' => (int) env('TELEGRAM_POLL_INTERVAL', 2),

    /*
     * Registered Telegram command classes.
     * Each must implement App\Telegram\Commands\TelegramCommandInterface.
     */
    'commands' => [
        \App\Telegram\Commands\StartCommand::class,
        \App\Telegram\Commands\HelpCommand::class,
        \App\Telegram\Commands\StatusCommand::class,
        \App\Telegram\Commands\DeployCommand::class,
        \App\Telegram\Commands\SitesCommand::class,
    ],

];
