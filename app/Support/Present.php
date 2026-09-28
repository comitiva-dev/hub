<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Message;
use App\Models\ToolServer;
use App\Models\UsageRecord;
use App\Models\User;
use App\Models\Workspace;
use stdClass;

/**
 * Models in the contract's shapes (`@comitiva/contract`, hub/*). Tests
 * validate every one against the JSON Schema of the same name.
 */
final class Present
{
    /** @return array<string, mixed> HubUser */
    public static function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }

    /** @return array<string, mixed> Workspace, with the viewer's role */
    public static function workspace(Workspace $workspace, string $role): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'role' => $role,
            'createdAt' => Iso::date($workspace->created_at),
        ];
    }

    /** @return array<string, mixed> Member */
    public static function member(Membership $membership): array
    {
        return [
            'user' => self::user($membership->user),
            'role' => $membership->role,
            'joinedAt' => Iso::date($membership->created_at),
        ];
    }

    /** @return array<string, mixed> Invitation */
    public static function invitation(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'workspaceId' => $invitation->workspace_id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'invitedBy' => self::user($invitation->inviter),
            'expiresAt' => Iso::date($invitation->expires_at),
            'createdAt' => Iso::date($invitation->created_at),
        ];
    }

    /** @return array<string, mixed> SharedAgent */
    public static function agent(Agent $agent): array
    {
        return [
            'id' => $agent->id,
            'workspaceId' => $agent->workspace_id,
            'name' => $agent->name,
            'avatar' => $agent->avatar,
            'provider' => $agent->provider,
            'model' => $agent->model,
            'role' => $agent->role,
            'params' => self::object($agent->params),
            'toolServerIds' => $agent->toolServers->pluck('id')->values()->all(),
            'permissionPolicy' => $agent->permission_policy,
            'tags' => $agent->tags ?? [],
            'createdBy' => $agent->created_by,
            'createdAt' => Iso::date($agent->created_at),
            'updatedAt' => Iso::date($agent->updated_at),
        ];
    }

    /** @return array<string, mixed> WorkspaceToolServer */
    public static function toolServer(ToolServer $server): array
    {
        return [
            'id' => $server->id,
            'workspaceId' => $server->workspace_id,
            'name' => $server->name,
            'transport' => 'http',
            'url' => $server->url,
            'headers' => self::object($server->headers),
            'enabled' => $server->enabled,
            'createdBy' => $server->created_by,
            'createdAt' => Iso::date($server->created_at),
        ];
    }

    /** @return array<string, mixed> Conversation (harness sessions are per desktop: always null here) */
    public static function conversation(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'agentId' => $conversation->agent_id,
            'title' => $conversation->title,
            'status' => $conversation->status,
            'harnessSessionId' => null,
            'archived' => $conversation->archived,
            'lastActivityAt' => Iso::date($conversation->last_activity_at),
            'createdAt' => Iso::date($conversation->created_at),
        ];
    }

    /** @return array<string, mixed>|null HubRunner */
    public static function runner(Conversation $conversation): ?array
    {
        $run = $conversation->activeRun;
        if ($run === null) {
            return null;
        }

        return [
            'userId' => $run->user_id,
            'name' => $run->user->name,
            'runId' => $run->id,
            'startedAt' => Iso::date($run->started_at),
        ];
    }

    /** @return array<string, mixed> HubConversationSummary */
    public static function summary(Conversation $conversation, int $unread): array
    {
        return [
            'conversation' => self::conversation($conversation),
            'workspaceId' => $conversation->workspace_id,
            'unread' => $unread,
            'pendingApproval' => $conversation->pending_approval,
            'runner' => self::runner($conversation),
        ];
    }

    /** @return array<string, mixed> Message */
    public static function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversationId' => $message->conversation_id,
            'role' => $message->role,
            'content' => $message->content ?? [],
            'status' => $message->status,
            'seq' => $message->seq,
            'createdAt' => Iso::date($message->created_at),
            'error' => $message->error,
            'author' => $message->author_id === null || $message->author === null
                ? null
                : ['id' => $message->author->id, 'name' => $message->author->name],
        ];
    }

    /** @return array<string, mixed> HubUsageRecord */
    public static function usageRecord(UsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'workspaceId' => $record->workspace_id,
            'userId' => $record->user_id,
            'agentId' => $record->agent_id,
            'conversationId' => $record->conversation_id,
            'messageId' => $record->message_id,
            'provider' => $record->provider,
            'model' => $record->model,
            'inputTokens' => $record->input_tokens,
            'outputTokens' => $record->output_tokens,
            'cacheReadTokens' => $record->cache_read_tokens,
            'cacheWriteTokens' => $record->cache_write_tokens,
            'estimated' => $record->estimated,
            'estimatedCostUsd' => $record->cost_usd,
            'costSource' => $record->cost_source,
            'costEstimated' => $record->cost_estimated,
            'latencyMs' => $record->latency_ms,
            'createdAt' => Iso::date($record->created_at),
        ];
    }

    /** A JSON object even when empty (PHP's `[]` would encode as a list). */
    public static function object(mixed $value): stdClass
    {
        if ($value instanceof stdClass) {
            return $value;
        }

        return (object) (is_array($value) ? $value : []);
    }
}
