<?php

namespace App\Services;

use App\Telegram\CommandDispatcher;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramBotService
{
    private int $offset = 0;

    public function __construct(
        private Api $telegram,
        private CommandDispatcher $dispatcher,
    ) {}

    /**
     * Poll Telegram for new updates using the long-polling getUpdates method.
     * Advances the internal offset after each batch so we never replay updates.
     *
     * @return array[]  Array of raw update arrays.
     */
    public function fetchUpdates(): array
    {
        try {
            $updates = $this->telegram->getUpdates([
                'offset'  => $this->offset,
                'limit'   => 100,
                'timeout' => 0,
            ]);
        } catch (TelegramSDKException $e) {
            Log::warning('TelegramBotService: getUpdates failed', ['error' => $e->getMessage()]);
            return [];
        }

        $raw = [];

        foreach ($updates as $update) {
            $data = $update->getRawResponse();
            $raw[] = $data;

            $updateId     = $data['update_id'] ?? 0;
            $this->offset = max($this->offset, $updateId + 1);
        }

        return $raw;
    }

    /**
     * Send a plain-text (HTML parse mode) message to a single chat.
     */
    public function sendMessage(int|string $chatId, string $text): void
    {
        try {
            $this->telegram->sendMessage([
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (TelegramSDKException $e) {
            Log::warning('TelegramBotService: sendMessage failed', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast a message to all allowed chat IDs from config.
     */
    public function broadcast(string $text): void
    {
        $chatIds = config('telegram.allowed_chat_ids', []);

        foreach ($chatIds as $chatId) {
            $this->sendMessage((int) $chatId, $text);
        }
    }

    public function getDispatcher(): CommandDispatcher
    {
        return $this->dispatcher;
    }
}
