<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Redis;

class StatusCommand implements TelegramCommandInterface
{
    public function getName(): string
    {
        return 'status';
    }

    public function getDescription(): string
    {
        return 'Check Redis signal for a project: /status {project}';
    }

    public function handle(array $args, int $chatId): string
    {
        $project = $args[0] ?? null;

        if (!$project) {
            return 'Usage: /status {project}';
        }

        $key   = "{$project}/commands";
        $value = Redis::connection('telegram')->get($key);

        if ($value === null) {
            return "No active signal for project: <b>{$project}</b>";
        }

        $decoded = json_decode($value, true);
        $pretty  = $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value;

        return "<b>{$project}</b> — active signal:\n<pre>{$pretty}</pre>";
    }
}
