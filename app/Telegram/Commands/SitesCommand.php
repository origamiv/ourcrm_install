<?php

namespace App\Telegram\Commands;

use Illuminate\Support\Facades\Redis;

class SitesCommand implements TelegramCommandInterface
{
    public function getName(): string
    {
        return 'sites';
    }

    public function getDescription(): string
    {
        return 'List all projects with active Redis signals';
    }

    public function handle(array $args, int $chatId): string
    {
        /** @var \Redis $client */
        $client = Redis::connection('telegram')->client();
        $keys   = $client->keys('*/commands');

        if (empty($keys)) {
            return 'No active signals in Redis right now.';
        }

        $lines = ['<b>Active signals:</b>', ''];

        foreach ($keys as $key) {
            $raw     = $client->get($key);
            $decoded = json_decode($raw, true);
            $display = $decoded
                ? json_encode($decoded, JSON_UNESCAPED_UNICODE)
                : (string) $raw;

            $lines[] = "• <code>{$key}</code>\n  {$display}";
        }

        return implode("\n", $lines);
    }
}
