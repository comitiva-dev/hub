<?php

namespace App\Realtime;

use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends HubEvents after the surrounding transaction commits, so nobody hears
 * of a change that was rolled back. A failed broadcast is reported, not
 * thrown: the change is stored and clients catch up on their next fetch.
 *
 * Channels (the contract's hub/events.ts):
 * - private-user.{userId}
 * - presence-workspace.{workspaceId}
 * - private-conversation.{conversationId}
 */
class Realtime
{
    public static function userChannel(string $userId): Channel
    {
        return new PrivateChannel("user.{$userId}");
    }

    public static function workspaceChannel(Workspace|string $workspace): Channel
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return new PresenceChannel("workspace.{$id}");
    }

    public static function conversationChannel(Conversation|string $conversation): Channel
    {
        $id = $conversation instanceof Conversation ? $conversation->id : $conversation;

        return new PrivateChannel("conversation.{$id}");
    }

    /** @param  array<string, mixed>  $payload  with `type` */
    public function send(Channel $channel, array $payload): void
    {
        $event = new HubBroadcast($channel, $payload);
        DB::afterCommit(function () use ($event) {
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** @param  array<string, mixed>  $payload */
    public function toUser(string $userId, array $payload): void
    {
        $this->send(self::userChannel($userId), $payload);
    }

    /** @param  array<string, mixed>  $payload */
    public function toWorkspace(Workspace|string $workspace, array $payload): void
    {
        $this->send(self::workspaceChannel($workspace), $payload);
    }

    /** @param  array<string, mixed>  $payload */
    public function toConversation(Conversation|string $conversation, array $payload): void
    {
        $this->send(self::conversationChannel($conversation), $payload);
    }
}
