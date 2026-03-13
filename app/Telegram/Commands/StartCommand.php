<?php

namespace App\Telegram\Commands;

class StartCommand implements TelegramCommandInterface
{
    public function getName(): string
    {
        return 'start';
    }

    public function getDescription(): string
    {
        return 'Welcome message';
    }

    public function handle(array $args, int $chatId): string
    {
        return "👋 <b>Welcome to OurCRM Bot!</b>\n\n"
            . "This bot monitors all OurCRM projects via Redis and lets you manage them.\n\n"
            . "Use /help to see available commands.";
    }
}
