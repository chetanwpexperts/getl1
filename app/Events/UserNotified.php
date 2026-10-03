<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "You have a new alert" to each person's private channel (user.{id}). It carries no content:
 * the browser fetches its own new alerts, so the bell and pop-ups always come from the server.
 */
class UserNotified implements ShouldBroadcastNow
{
    /** @param  list<int>  $userIds */
    public function __construct(public array $userIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('user.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'alert';
    }

    public function broadcastWith(): array
    {
        return ['new' => true];
    }
}
