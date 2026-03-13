<?php

namespace App\Telegram\Commands;

interface TelegramCommandInterface
{
    /**
     * Command trigger without the leading slash, e.g. "start", "deploy".
     */
    public function getName(): string;

    /**
     * Short description shown in /help output.
     */
    public function getDescription(): string;

    /**
     * Execute the command.
     *
     * @param  string[]  $args   Tokens after the command name (may be empty).
     * @param  int       $chatId Originating Telegram chat ID.
     * @return string            Reply text to send back (HTML parse mode).
     */
    public function handle(array $args, int $chatId): string;
}
