<?php

namespace App\Telegram\Commands;

use App\Telegram\CommandDispatcher;

class HelpCommand implements TelegramCommandInterface
{
    public function __construct(private CommandDispatcher $dispatcher) {}

    public function getName(): string
    {
        return 'help';
    }

    public function getDescription(): string
    {
        return 'List all available commands';
    }

    public function handle(array $args, int $chatId): string
    {
        $lines = ['<b>Available commands:</b>', ''];

        foreach ($this->dispatcher->getRegisteredCommands() as $command) {
            $lines[] = sprintf('/<b>%s</b> — %s', $command->getName(), $command->getDescription());
        }

        return implode("\n", $lines);
    }
}
