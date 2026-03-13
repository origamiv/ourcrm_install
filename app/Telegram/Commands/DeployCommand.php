<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Redis;

class DeployCommand implements TelegramCommandInterface
{
    public function getName(): string
    {
        return 'deploy';
    }

    public function getDescription(): string
    {
        return 'Request a deploy for a project: /deploy {project}';
    }

    public function handle(array $args, int $chatId): string
    {
        $project = $args[0] ?? null;

        if (!$project) {
            return 'Usage: /deploy {project}';
        }

        $payload = json_encode([
            'action'       => 'deploy',
            'requested_by' => $chatId,
            'requested_at' => now()->toIso8601String(),
        ]);

        Redis::connection('telegram')->set("{$project}/commands", $payload);

        return "🚀 Deploy requested for <b>{$project}</b>.\n"
            . "The site will pick it up on its next Redis poll.";
    }
}
