<?php

namespace App\Console\Commands;

use App\Services\Telegram\IncomingMessageHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Throwable;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll {--once : Fetch and process a single batch of updates, then exit}';

    protected $description = 'Receive Telegram updates via long polling and store incoming messages';

    private bool $shouldStop = false;

    public function handle(Api $telegram, IncomingMessageHandler $handler): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        // Long polling and webhooks are mutually exclusive.
        $telegram->deleteWebhook();

        $this->info('Polling Telegram for updates...');

        $offset = null;

        do {
            try {
                $updates = $telegram->getUpdates(array_filter([
                    'offset' => $offset,
                    'timeout' => 30,
                    'allowed_updates' => ['message'],
                ]), false);
            } catch (TelegramSDKException $e) {
                Log::error('Telegram getUpdates failed', ['error' => $e->getMessage()]);
                $this->backoff();

                continue;
            }

            foreach ($updates as $update) {
                try {
                    $handler->handle($update);
                } catch (Throwable $e) {
                    // Do not confirm the update: it is fetched again after the pause.
                    report($e);
                    $this->backoff();

                    continue 2;
                }

                $offset = $update->updateId + 1;
            }
        } while (! $this->option('once') && ! $this->shouldStop);

        return self::SUCCESS;
    }

    private function backoff(): void
    {
        if (! $this->option('once')) {
            sleep(3);
        }
    }
}
