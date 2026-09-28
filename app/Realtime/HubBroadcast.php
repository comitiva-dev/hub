<?php

namespace App\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * One event on one channel, in the contract's HubEvent shape: the Pusher
 * event name is its `type`. Sent at once (no queue): live text cannot wait.
 */
class HubBroadcast implements ShouldBroadcastNow
{
    /** @param  array<string, mixed>  $payload  includes `type` */
    public function __construct(
        public readonly Channel $channel,
        public readonly array $payload,
    ) {}

    public function type(): string
    {
        return (string) $this->payload['type'];
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [$this->channel];
    }

    public function broadcastAs(): string
    {
        return $this->type();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
