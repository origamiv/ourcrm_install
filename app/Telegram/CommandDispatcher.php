<?php

namespace App\Telegram;

use App\Telegram\Commands\TelegramCommandInterface;

class CommandDispatcher
{
    /** @var TelegramCommandInterface[] keyed by command name */
    private array $commands = [];

    /**
     * Register command classes from config('telegram.commands').
     * Each class is resolved via the service container to support constructor injection.
     *
     * @param  string[]  $commandClasses  Array of FQCN strings.
     */
    public function register(array $commandClasses): void
    {
        foreach ($commandClasses as $class) {
            /** @var TelegramCommandInterface $instance */
            $instance = app()->make($class);
            $this->commands[$instance->getName()] = $instance;
        }
    }

    /**
     * Parse an incoming Telegram update and dispatch to the matching command.
     *
     * Returns the reply string, or null if the message is not a bot command.
     *
     * @param  array  $update  Raw Telegram Update array.
     */
    public function dispatch(array $update): ?string
    {
        $text   = data_get($update, 'message.text', '');
        $chatId = (int) data_get($update, 'message.chat.id', 0);

        if (!str_starts_with($text, '/')) {
            return null;
        }

        // Strip bot @mention suffix, e.g. "/start@MyBot" → "/start"
        $text = preg_replace('/@\S+/', '', $text);
        $text = trim($text);

        $parts   = preg_split('/\s+/', $text);
        $name    = ltrim($parts[0], '/');
        $args    = array_slice($parts, 1);

        if (!isset($this->commands[$name])) {
            return "Unknown command: <code>/{$name}</code>\nUse /help to see available commands.";
        }

        return $this->commands[$name]->handle($args, $chatId);
    }

    /**
     * Return all registered command instances (used by HelpCommand).
     *
     * @return TelegramCommandInterface[]
     */
    public function getRegisteredCommands(): array
    {
        return array_values($this->commands);
    }
}
