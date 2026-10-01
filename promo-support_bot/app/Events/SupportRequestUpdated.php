<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells connected operator UIs that a request (or the queue itself) changed.
 * Sent synchronously because the bot process runs no queue worker.
 */
class SupportRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $requestId) {}

    /**
     * Broadcast without ever failing the caller: a realtime outage must not
     * lose messages or break the bot.
     */
    public static function publish(int $requestId): void
    {
        try {
            static::dispatch($requestId);
        } catch (Throwable $e) {
            Log::warning('Could not broadcast request update', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('support-requests')];
    }

    public function broadcastAs(): string
    {
        return 'updated';
    }

    /** @return array{request_id: int} */
    public function broadcastWith(): array
    {
        return ['request_id' => $this->requestId];
    }
}
