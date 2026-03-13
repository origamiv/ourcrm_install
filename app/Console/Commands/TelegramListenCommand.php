<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use App\Telegram\RedisSignalProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TelegramListenCommand extends Command
{
    protected $signature   = 'telegram:listen';
    protected $description = 'Poll Telegram API and Redis for signals (runs as a daemon via PM2)';

    // Dependencies are resolved lazily in handle() so that `artisan list`
    // does not fail when TELEGRAM_BOT_TOKEN is not yet configured.

    public function handle(): int
    {
        $bot            = app(TelegramBotService::class);
        $redisProcessor = app(RedisSignalProcessor::class);

        $this->info('Telegram bot listener started.');

        $interval = (int) config('telegram.poll_interval', 2);

        while (true) {
            try {
                // --- Step 1: Poll Telegram for new user messages ---
                $updates = $bot->fetchUpdates();

                foreach ($updates as $update) {
                    $this->handleUpdate($bot, $update);
                }

                // --- Step 2: Check Redis for signals from sites ---
                $count = $redisProcessor->processSignals();

                if ($count > 0) {
                    $this->info("[" . date('H:i:s') . "] Processed {$count} Redis signal(s).");
                }
            } catch (\Throwable $e) {
                // Log the error but never let the loop crash — PM2 will restart
                // on a crash, but we prefer to keep running and just log.
                $this->error('Loop error: ' . $e->getMessage());
                Log::error('telegram:listen loop error', [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }

            sleep($interval);
        }
    }

    /**
     * Process a single Telegram update: check whitelist, dispatch command, reply.
     */
    private function handleUpdate(TelegramBotService $bot, array $update): void
    {
        $chatId = (int) data_get($update, 'message.chat.id', 0);
        $text   = (string) data_get($update, 'message.text', '');

        if (!$chatId) {
            return;
        }

        $allowed = config('telegram.allowed_chat_ids', []);

        if (!empty($allowed) && !in_array((string) $chatId, array_map('strval', $allowed), true)) {
            $this->warn("Rejected message from unauthorized chat: {$chatId}");
            return;
        }

        if (!str_starts_with($text, '/')) {
            return;
        }

        $this->line("[" . date('H:i:s') . "] Command from {$chatId}: {$text}");

        $response = $bot->getDispatcher()->dispatch($update);

        if ($response !== null) {
            $bot->sendMessage($chatId, $response);
        }
    }
}
