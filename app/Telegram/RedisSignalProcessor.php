<?php

namespace App\Telegram;

use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisSignalProcessor
{
    public function __construct(private TelegramBotService $bot) {}

    /**
     * Scan Redis for all {project}/commands keys.
     * For each found key: read value → send Telegram broadcast → DEL the key.
     *
     * @return int  Number of signals processed.
     */
    public function processSignals(): int
    {
        /** @var \Redis $client */
        $client = Redis::connection('telegram')->client();
        $keys   = $client->keys('*/commands');

        if (empty($keys)) {
            return 0;
        }

        $count = 0;

        foreach ($keys as $key) {
            $raw = $client->get($key);

            if ($raw === null || $raw === false) {
                continue;
            }

            $payload = json_decode($raw, true);
            $message = $this->formatSignal($key, $payload ?? ['raw' => $raw]);

            $this->bot->broadcast($message);
            $client->del($key);

            Log::info('TelegramBot: processed Redis signal', ['key' => $key, 'payload' => $raw]);
            $count++;
        }

        return $count;
    }

    /**
     * Format a Redis key + decoded payload into a readable Telegram message.
     */
    private function formatSignal(string $key, array $payload): string
    {
        // Extract project name from key format "{project}/commands"
        $project = explode('/', $key)[0] ?? $key;

        $action = $payload['action'] ?? 'signal';
        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "📡 <b>Signal from [{$project}]</b>\n"
            . "Action: <code>{$action}</code>\n\n"
            . "<pre>{$pretty}</pre>";
    }
}
