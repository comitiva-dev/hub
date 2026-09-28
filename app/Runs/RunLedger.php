<?php

namespace App\Runs;

use App\Events\RunCompleted;
use App\Exceptions\HubException;
use App\Extension\RunGate;
use App\Extension\UsageMeter;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Run;
use App\Models\ToolApproval;
use App\Models\UsageRecord;
use App\Models\User;
use App\Realtime\Realtime;
use App\Support\Blocks;
use App\Support\Iso;
use App\Support\Present;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Turns run by desktops (ADR 0017 in comitiva). A desktop takes the
 * conversation's run lock, publishes its runner's events in batches, and
 * finishes with the final content and usage. The hub applies every event to
 * the stored reply under the conversation's row lock, numbers it with the
 * conversation's `rev`, and broadcasts it, so every member (the executing one
 * included) sees one ordered stream.
 *
 * A run holds a lease renewed by batches and heartbeats; when it lapses the
 * reply ends as `error { interrupted }` and the conversation is free again.
 */
class RunLedger
{
    public function __construct(
        private readonly Realtime $realtime,
        private readonly RunGate $gate,
        private readonly UsageMeter $meter,
    ) {}

    /**
     * @param  list<stdClass>|null  $content  null retries the last (failed) reply
     * @return array<string, mixed> HubRunStartResult
     */
    public function start(Conversation $conversation, User $user, ?int $tokenId, string $runId, ?array $content): array
    {
        return DB::transaction(function () use ($conversation, $user, $tokenId, $runId, $content) {
            $conversation = $this->lock($conversation->id);
            $refusal = $this->gate->refuse($user, $conversation);
            if ($refusal !== null) {
                throw HubException::forbidden($refusal);
            }
            $active = Run::where('conversation_id', $conversation->id)->where('status', 'active')->lockForUpdate()->first();
            if ($active !== null) {
                if (! $active->leaseExpired()) {
                    throw new HubException('conversation_busy', 'This conversation is already running', 409);
                }
                $this->expireLocked($active, $conversation);
            }
            if (Run::whereKey($runId)->exists()) {
                throw HubException::invalid('This run id was already used');
            }

            $now = Iso::now();
            $userMessage = null;
            if ($content !== null) {
                if (! Blocks::hasContent($content)) {
                    throw HubException::invalid('The message is empty');
                }
                $this->checkAttachments($conversation, $content);
                $seq = (int) $conversation->messages()->max('seq');
                $userMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'seq' => $seq + 1,
                    'role' => 'user',
                    'content' => $content,
                    'status' => 'complete',
                    'author_id' => $user->id,
                    'search_text' => Blocks::searchText($content),
                ]);
                $reply = Message::create([
                    'conversation_id' => $conversation->id,
                    'seq' => $seq + 2,
                    'role' => 'assistant',
                    'content' => [],
                    'status' => 'streaming',
                    'author_id' => $user->id,
                ]);
                if ($conversation->title === null) {
                    $title = Blocks::placeholderTitle($content);
                    if ($title !== null) {
                        $conversation->title = $title;
                        $conversation->title_source = 'placeholder';
                    }
                }
            } else {
                $reply = $conversation->messages()->orderByDesc('seq')->first();
                if ($reply === null || $reply->role !== 'assistant' || $reply->status !== 'error') {
                    throw HubException::invalid('The last reply did not fail');
                }
                $reply->update(['content' => [], 'status' => 'streaming', 'error' => null, 'search_text' => null, 'author_id' => $user->id]);
            }

            $conversation->status = 'running';
            $conversation->pending_approval = null;
            $conversation->last_activity_at = $now;
            $run = Run::create([
                'id' => $runId,
                'conversation_id' => $conversation->id,
                'reply_message_id' => $reply->id,
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'status' => 'active',
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'started_at' => $now,
            ]);

            if ($userMessage !== null) {
                $this->messageEvent($conversation, 'message.created', $userMessage);
                $this->messageEvent($conversation, 'message.created', $reply);
            } else {
                $this->messageEvent($conversation, 'message.updated', $reply);
            }
            $conversation->save();
            $this->realtime->toConversation($conversation, [
                'type' => 'run.started',
                'conversationId' => $conversation->id,
                'messageId' => $reply->id,
                'runId' => $run->id,
                'userId' => $user->id,
            ]);
            $this->conversationUpdated($conversation);

            $history = $conversation->messages()->with('author')->where('seq', '<', $reply->seq)->orderBy('seq')->get();

            return [
                'runId' => $run->id,
                'userMessage' => $userMessage === null ? null : Present::message($userMessage),
                'reply' => Present::message($reply),
                'conversation' => Present::conversation($conversation),
                'history' => $history->map(fn (Message $m) => Present::message($m))->values()->all(),
                'leaseSeconds' => $this->leaseSeconds(),
            ];
        });
    }

    /**
     * Applies a batch of run events (HubRunEvent). A batch at or below the
     * last one applied was a retry: acknowledged, not applied again.
     *
     * @param  list<stdClass>  $events
     */
    public function apply(Run $run, User $user, ?int $tokenId, int $batch, array $events): int
    {
        return $this->asOwner(fn () => DB::transaction(function () use ($run, $user, $tokenId, $batch, $events) {
            [$run, $conversation] = $this->owned($run, $user, $tokenId);
            if ($batch <= $run->last_batch) {
                return $conversation->rev;
            }
            $reply = Message::whereKey($run->reply_message_id)->lockForUpdate()->firstOrFail();
            $content = $reply->content ?? [];
            $statusChanged = false;
            $base = ['conversationId' => $conversation->id, 'messageId' => $reply->id, 'runId' => $run->id];

            foreach ($events as $event) {
                if ($event->runId !== $run->id) {
                    throw HubException::invalid('An event belongs to another run');
                }
                switch ($event->type) {
                    case 'run.text_delta':
                        $content = Blocks::appendText($content, $event->text);
                        $payload = ['type' => 'run.text_delta', ...$base, 'rev' => $conversation->nextRev(), 'text' => $event->text];
                        if (isset($event->ts)) {
                            $payload['ts'] = $event->ts;
                        }
                        $this->realtime->toConversation($conversation, $payload);
                        break;
                    case 'run.block':
                        $content[] = $event->block;
                        $this->realtime->toConversation($conversation, [
                            'type' => 'run.block', ...$base, 'rev' => $conversation->nextRev(), 'block' => $event->block,
                        ]);
                        break;
                    case 'run.tool_call':
                        $this->realtime->toConversation($conversation, [
                            'type' => 'run.tool_call', ...$base,
                            'toolUseId' => $event->toolUseId,
                            'toolServerId' => $event->toolServerId,
                            'toolName' => $event->toolName,
                            'input' => $event->input ?? null,
                            'requiresApproval' => $event->requiresApproval,
                        ]);
                        if ($event->requiresApproval) {
                            $conversation->status = 'awaiting-approval';
                            $conversation->pending_approval = (object) [
                                'toolUseId' => $event->toolUseId,
                                'toolServerId' => $event->toolServerId,
                                'toolName' => $event->toolName,
                                'input' => $event->input ?? null,
                            ];
                            $statusChanged = true;
                        }
                        break;
                    case 'run.tool_result':
                        $content[] = (object) [
                            'type' => 'tool_result',
                            'toolUseId' => $event->toolUseId,
                            'content' => $event->output,
                            'isError' => $event->isError,
                            'durationMs' => $event->durationMs,
                        ];
                        if (($conversation->pending_approval->toolUseId ?? null) === $event->toolUseId) {
                            $conversation->pending_approval = null;
                            $conversation->status = 'running';
                            $statusChanged = true;
                        }
                        $this->realtime->toConversation($conversation, [
                            'type' => 'run.tool_result', ...$base, 'rev' => $conversation->nextRev(),
                            'toolUseId' => $event->toolUseId,
                            'output' => $event->output,
                            'isError' => $event->isError,
                            'durationMs' => $event->durationMs,
                        ]);
                        break;
                }
            }

            $reply->content = $content;
            $reply->save();
            $run->last_batch = $batch;
            $run->lease_expires_at = Iso::now()->addSeconds($this->leaseSeconds());
            $run->save();
            $conversation->save();
            if ($statusChanged) {
                $this->conversationUpdated($conversation);
            }

            return $conversation->rev;
        }));
    }

    /** The executing member's answer to the pending tool call (the call acts on their machine). */
    public function approve(Run $run, User $user, ?int $tokenId, string $toolUseId, string $decision): void
    {
        $this->asOwner(fn () => DB::transaction(function () use ($run, $user, $tokenId, $toolUseId, $decision) {
            [$run, $conversation] = $this->owned($run, $user, $tokenId);
            $pending = $conversation->pending_approval;
            if (($pending->toolUseId ?? null) !== $toolUseId) {
                throw HubException::invalid('Nothing is waiting for that decision');
            }
            ToolApproval::create([
                'conversation_id' => $conversation->id,
                'run_id' => $run->id,
                'tool_use_id' => $toolUseId,
                'tool_server_id' => $pending->toolServerId,
                'tool_name' => $pending->toolName,
                'input' => $pending->input ?? null,
                'decision' => $decision,
                'decided_by' => $user->id,
                'decided_at' => Iso::now(),
            ]);
            $conversation->pending_approval = null;
            $conversation->status = 'running';
            $conversation->save();
            $run->lease_expires_at = Iso::now()->addSeconds($this->leaseSeconds());
            $run->save();
            $this->conversationUpdated($conversation);
        }));
    }

    public function heartbeat(Run $run, User $user, ?int $tokenId): void
    {
        $this->asOwner(fn () => DB::transaction(function () use ($run, $user, $tokenId) {
            [$run] = $this->owned($run, $user, $tokenId);
            $run->lease_expires_at = Iso::now()->addSeconds($this->leaseSeconds());
            $run->save();
        }));
    }

    /**
     * The end of a run: final content, status and usage in one transaction.
     *
     * @return array<string, mixed> HubRunFinishResult
     */
    public function finish(Run $run, User $user, ?int $tokenId, stdClass $input): array
    {
        $result = $this->asOwner(fn () => DB::transaction(function () use ($run, $user, $tokenId, $input) {
            [$run, $conversation] = $this->owned($run, $user, $tokenId);
            $reply = Message::whereKey($run->reply_message_id)->lockForUpdate()->firstOrFail();
            $now = Iso::now();

            $reply->content = $input->content;
            $reply->status = $input->status;
            $reply->error = $input->status === 'error' ? $input->error : null;
            $reply->search_text = Blocks::searchText($input->content);
            $reply->save();

            $usage = null;
            if ($input->usage !== null) {
                $u = $input->usage;
                $usage = UsageRecord::create([
                    'workspace_id' => $conversation->workspace_id,
                    'user_id' => $run->user_id,
                    'agent_id' => $conversation->agent_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $reply->id,
                    'provider' => $u->provider,
                    'model' => $u->model,
                    'input_tokens' => $u->inputTokens,
                    'output_tokens' => $u->outputTokens,
                    'cache_read_tokens' => $u->cacheReadTokens,
                    'cache_write_tokens' => $u->cacheWriteTokens,
                    'estimated' => $u->estimated,
                    'cost_usd' => $u->estimatedCostUsd,
                    'cost_source' => $u->costSource,
                    'cost_estimated' => $u->costEstimated,
                    'latency_ms' => $u->latencyMs,
                    'created_at' => $now,
                ]);
            }

            $conversation->status = $input->status === 'error' ? 'error' : 'idle';
            $conversation->pending_approval = null;
            $conversation->last_activity_at = $now;
            $run->status = 'finished';
            $run->finished_at = $now;
            $run->save();

            $this->messageEvent($conversation, 'message.updated', $reply);
            $conversation->save();
            $base = ['conversationId' => $conversation->id, 'messageId' => $reply->id, 'runId' => $run->id];
            if ($input->status === 'error') {
                $error = $input->error ?? (object) ['code' => 'internal', 'message' => 'The run failed', 'retryable' => false];
                $this->realtime->toConversation($conversation, [
                    'type' => 'run.error', ...$base,
                    'code' => $error->code, 'message' => $error->message, 'retryable' => $error->retryable,
                ]);
            } else {
                $this->realtime->toConversation($conversation, [
                    'type' => 'run.done', ...$base,
                    'stopReason' => $input->stopReason ?? ($input->status === 'cancelled' ? 'cancelled' : 'end_turn'),
                ]);
            }
            $this->conversationUpdated($conversation);

            $this->completed($run, $input->status, $usage);

            return ['message' => Present::message($reply), 'conversation' => Present::conversation($conversation)];
        }));

        return $result;
    }

    /**
     * Asks the executing desktop to stop. The member who started the run and
     * admins may; a run whose desktop is gone is expired instead.
     */
    public function requestCancel(Conversation $conversation, Membership $membership): void
    {
        DB::transaction(function () use ($conversation, $membership) {
            $conversation = $this->lock($conversation->id);
            $run = Run::where('conversation_id', $conversation->id)->where('status', 'active')->lockForUpdate()->first();
            if ($run === null) {
                return;
            }
            if ($run->leaseExpired()) {
                $this->expireLocked($run, $conversation);

                return;
            }
            if ($run->user_id !== $membership->user_id && ! $membership->isAdmin()) {
                throw HubException::forbidden('Only the member running it or an admin can stop this reply');
            }
            $this->realtime->toConversation($conversation, [
                'type' => 'run.cancel_requested',
                'conversationId' => $conversation->id,
                'runId' => $run->id,
                'byUserId' => $membership->user_id,
            ]);
        });
    }

    /** Ends every run whose lease lapsed. Returns how many. */
    public function expireStale(): int
    {
        $ids = Run::where('status', 'active')->where('lease_expires_at', '<', Iso::now())->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $run = Run::whereKey($id)->first();
                if ($run === null) {
                    return 0;
                }
                $conversation = $this->lock($run->conversation_id);
                $run = Run::whereKey($id)->lockForUpdate()->first();
                if ($run === null || $run->status !== 'active' || ! $run->leaseExpired()) {
                    return 0;
                }
                $this->expireLocked($run, $conversation);
                $conversation->save();

                return 1;
            });
        }

        return $count;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Runs a request from the executing desktop. When its lease lapsed, the
     * run is expired in a transaction of its own and the desktop is told.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function asOwner(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (LeaseLapsed) {
            $this->expireStale();
            throw new HubException('run_expired', 'The hub stopped waiting for this run', 409);
        }
    }

    private function lock(string $conversationId): Conversation
    {
        return Conversation::whereKey($conversationId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The run, locked with its conversation, after checking that the caller
     * is the desktop running it and that it is still alive.
     *
     * @return array{Run, Conversation}
     */
    private function owned(Run $run, User $user, ?int $tokenId): array
    {
        $conversation = $this->lock($run->conversation_id);
        $run = Run::whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->user_id !== $user->id || ($run->token_id !== null && $run->token_id !== $tokenId)) {
            throw HubException::forbidden('This run belongs to another desktop');
        }
        if ($run->status === 'expired') {
            throw new HubException('run_expired', 'The hub stopped waiting for this run', 409);
        }
        if ($run->status !== 'active') {
            throw HubException::invalid('This run has finished');
        }
        if ($run->leaseExpired()) {
            throw new LeaseLapsed;
        }

        return [$run, $conversation];
    }

    /** Ends a run whose desktop stopped renewing its lease. Caller holds the locks and saves. */
    private function expireLocked(Run $run, Conversation $conversation): void
    {
        $reply = Message::whereKey($run->reply_message_id)->lockForUpdate()->first();
        $now = Iso::now();
        $run->status = 'expired';
        $run->finished_at = $now;
        $run->save();
        $error = (object) [
            'code' => 'interrupted',
            'message' => 'The desktop running this reply stopped responding',
            'retryable' => true,
        ];
        if ($reply !== null && $reply->status === 'streaming') {
            $reply->status = 'error';
            $reply->error = $error;
            $reply->search_text = Blocks::searchText($reply->content ?? []);
            $reply->save();
            $this->messageEvent($conversation, 'message.updated', $reply);
        }
        $conversation->status = 'error';
        $conversation->pending_approval = null;
        $this->realtime->toConversation($conversation, [
            'type' => 'run.error',
            'conversationId' => $conversation->id,
            'messageId' => $run->reply_message_id,
            'runId' => $run->id,
            ...(array) $error,
        ]);
        $conversation->save();
        $this->conversationUpdated($conversation);
        $this->completed($run, 'expired', null);
    }

    /** @param  list<stdClass>  $content */
    private function checkAttachments(Conversation $conversation, array $content): void
    {
        $ids = Blocks::attachmentIds($content);
        if ($ids === []) {
            return;
        }
        $known = Attachment::where('workspace_id', $conversation->workspace_id)->whereIn('id', $ids)->count();
        if ($known !== count(array_unique($ids))) {
            throw HubException::invalid('An attachment is not in this workspace');
        }
    }

    private function messageEvent(Conversation $conversation, string $type, Message $message): void
    {
        $this->realtime->toConversation($conversation, [
            'type' => $type,
            'message' => Present::message($message),
            'rev' => $conversation->nextRev(),
        ]);
    }

    private function conversationUpdated(Conversation $conversation): void
    {
        $conversation->load('activeRun.user');
        $this->realtime->toWorkspace($conversation->workspace_id, [
            'type' => 'conversation.updated',
            'workspaceId' => $conversation->workspace_id,
            'conversation' => Present::conversation($conversation),
            'pendingApproval' => $conversation->pending_approval,
            'runner' => Present::runner($conversation),
        ]);
    }

    private function completed(Run $run, string $status, ?UsageRecord $usage): void
    {
        DB::afterCommit(function () use ($run, $status, $usage) {
            if ($usage !== null) {
                $this->meter->record($usage);
            }
            RunCompleted::dispatch($run, $status, $usage);
        });
    }

    private function leaseSeconds(): int
    {
        return max(5, (int) config('hub.run_lease_seconds'));
    }
}
