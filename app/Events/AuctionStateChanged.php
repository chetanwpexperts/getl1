<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Pushes an auction state snapshot to one private channel. Sent synchronously right after the
 * bid transaction commits (no queue delay). Each audience gets its own payload.
 */
class AuctionStateChanged implements ShouldBroadcastNow
{
    public function __construct(public string $channel, public array $state) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'state';
    }

    /** Socket messages stay small; a board too big for one message tells the browser to fetch it. */
    public const MAX_PAYLOAD_BYTES = 8000;

    public function broadcastWith(): array
    {
        if (strlen(json_encode($this->state)) > self::MAX_PAYLOAD_BYTES) {
            return ['id' => $this->state['id'] ?? null, 'refresh' => true];
        }

        return $this->state;
    }
}
