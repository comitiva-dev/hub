<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen where (the contract's hub/events.ts). Presence on a
 * workspace channel tells members who is online.
 */
Broadcast::channel('user.{userId}', fn (User $user, string $userId) => $user->id === $userId);

Broadcast::channel('workspace.{workspaceId}', function (User $user, string $workspaceId) {
    $workspace = Workspace::find($workspaceId);
    if ($workspace === null || $workspace->membershipOf($user) === null) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

Broadcast::channel('conversation.{conversationId}', function (User $user, string $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation !== null && $conversation->workspace->membershipOf($user) !== null;
});
