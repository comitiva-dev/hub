<?php

use App\Events\RunCompleted;
use App\Extension\RunGate;
use App\Extension\UsageMeter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Run;
use App\Models\ToolApproval;
use App\Models\UsageRecord;
use App\Models\User;
use App\Realtime\HubBroadcast;
use App\Support\Blocks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/** A workspace, a shared agent, one of its conversations, and two members (Ana, Bea). */
function runSetup(): array
{
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'));
    $conversation = conversationIn($ws);

    return [$ws, $conversation, $ana, $bea];
}

function startRun(Conversation $c, ?array $content = [['type' => 'text', 'text' => "Hello there\nsecond line"]]): array
{
    $runId = (string) Str::ulid();
    $body = ['runId' => $runId];
    if ($content !== null) {
        $body['content'] = $content;
    }
    $response = test()->postJson("/api/v1/conversations/{$c->id}/runs", $body);

    return [$runId, $response];
}

function usageInput(): array
{
    return [
        'provider' => 'anthropic', 'model' => 'claude-haiku-4-5',
        'inputTokens' => 12, 'outputTokens' => 5, 'cacheReadTokens' => null, 'cacheWriteTokens' => null,
        'estimated' => false, 'estimatedCostUsd' => 0.000037, 'costSource' => 'table', 'costEstimated' => false,
        'latencyMs' => 840,
    ];
}

it('starts a turn: stores the message and an empty reply, takes the lock, returns the history', function () {
    [$ws, $c, $ana] = runSetup();
    messageIn($c, 1, 'user', 'Earlier');
    messageIn($c, 2, 'assistant', 'Earlier reply');
    actingAsToken($ana);
    [$runId, $response] = startRun($c);

    $response->assertCreated();
    expectContract($response, 'HubRunStartResult');
    expect($response->json())->toMatchArray(['runId' => $runId, 'leaseSeconds' => 30]);
    expect($response->json('userMessage'))->toMatchArray(['seq' => 3, 'role' => 'user', 'status' => 'complete']);
    expect($response->json('reply'))->toMatchArray(['seq' => 4, 'role' => 'assistant', 'status' => 'streaming', 'content' => []]);
    expect(array_column($response->json('history'), 'seq'))->toBe([1, 2, 3]);
    expect($response->json('conversation'))->toMatchArray(['status' => 'running', 'title' => 'Hello there']);

    $c->refresh();
    expect($c->rev)->toBe(2);
    expect(broadcasts('message.created')->pluck('payload.rev')->all())->toBe([1, 2]);
    expect(broadcasts('run.started')->first()->payload)->toMatchArray(['runId' => $runId, 'userId' => $ana->id]);
    expect(broadcasts('conversation.updated')->last()->payload['runner'])->toMatchArray(['userId' => $ana->id, 'name' => 'Ana', 'runId' => $runId]);
});

it('lets one desktop run a conversation at a time', function () {
    [, $c, $ana, $bea] = runSetup();
    actingAsToken($ana);
    startRun($c)[1]->assertCreated();
    actingAsToken($bea);
    expectError(startRun($c)[1], 409, 'conversation_busy');
    expect(Message::count())->toBe(2);
});

it('refuses blank messages and attachments from elsewhere', function () {
    [$ws, $c, $ana] = runSetup();
    actingAsToken($ana);
    expectError(startRun($c, [['type' => 'text', 'text' => '   ']])[1], 422, 'invalid_request');
    expectError(startRun($c, [['type' => 'image', 'name' => 'x.png', 'source' => ['kind' => 'file', 'path' => (string) Str::ulid()]]])[1], 422, 'invalid_request');
    expect(Run::count())->toBe(0);
});

it('applies event batches in order, numbers them, and ignores a retried batch', function () {
    [, $c, $ana] = runSetup();
    actingAsToken($ana);
    [$runId] = startRun($c);

    $batch1 = ['batch' => 1, 'events' => [
        ['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'Hel', 'ts' => 1000.5],
        ['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'lo', 'ts' => 1001.5],
    ]];
    $this->postJson("/api/v1/runs/{$runId}/events", $batch1)->assertOk()->assertJson(['rev' => 4]);
    $this->postJson("/api/v1/runs/{$runId}/events", $batch1)->assertOk()->assertJson(['rev' => 4]);

    $reply = Message::where('role', 'assistant')->first();
    expect($reply->content)->toEqual([(object) ['type' => 'text', 'text' => 'Hello']]);
    $deltas = broadcasts('run.text_delta');
    expect($deltas->pluck('payload.rev')->all())->toBe([3, 4]);
    expect($deltas->first()->payload)->toMatchArray(['conversationId' => $c->id, 'messageId' => $reply->id, 'runId' => $runId, 'text' => 'Hel', 'ts' => 1000.5]);

    // A page read now lines up with the stream.
    $page = $this->getJson("/api/v1/conversations/{$c->id}/messages")->json();
    expect($page['rev'])->toBe(4);
    expect(end($page['messages'])['content'])->toEqual([['type' => 'text', 'text' => 'Hello']]);

    expectError($this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 2, 'events' => [
        ['type' => 'run.text_delta', 'runId' => (string) Str::ulid(), 'text' => 'x'],
    ]]), 422, 'invalid_request');
    expectError($this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 2, 'events' => [
        ['type' => 'run.usage', 'runId' => $runId, 'inputTokens' => 1, 'outputTokens' => 1, 'estimated' => false],
    ]]), 422, 'invalid_request');
});

it('waits on an approval only its desktop answers, then finishes with the final content and usage', function () {
    Event::fake([HubBroadcast::class, RunCompleted::class]);
    $meter = new class implements UsageMeter
    {
        public array $recorded = [];

        public function record(UsageRecord $record): void
        {
            $this->recorded[] = $record->id;
        }
    };
    app()->instance(UsageMeter::class, $meter);
    [$ws, $c, $ana, $bea] = runSetup();
    actingAsToken($ana, 'laptop');
    [$runId] = startRun($c);
    $toolUse = ['type' => 'tool_use', 'id' => 'toolu_1', 'toolServerId' => 'filesystem', 'name' => 'fs__write_file', 'input' => ['path' => 'a.txt', 'content' => 'hi']];
    $this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 1, 'events' => [
        ['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'Writing it.'],
        ['type' => 'run.block', 'runId' => $runId, 'block' => $toolUse],
        ['type' => 'run.tool_call', 'runId' => $runId, 'toolUseId' => 'toolu_1', 'toolServerId' => 'filesystem', 'toolName' => 'write_file', 'input' => ['path' => 'a.txt', 'content' => 'hi'], 'requiresApproval' => true],
    ]])->assertOk();
    expect($c->refresh()->status)->toBe('awaiting-approval');
    $summary = collect($this->getJson("/api/v1/workspaces/{$ws->id}/conversations")->json())->firstWhere('conversation.id', $c->id);
    expect($summary['pendingApproval'])->toMatchArray(['toolUseId' => 'toolu_1', 'toolName' => 'write_file']);

    // Another member, and another desktop of the same member, cannot answer or publish.
    actingAsToken($bea);
    expectError($this->postJson("/api/v1/runs/{$runId}/approvals", ['toolUseId' => 'toolu_1', 'decision' => 'allow']), 403, 'forbidden');
    actingAsToken($ana, 'desktop');
    expectError($this->postJson("/api/v1/runs/{$runId}/heartbeat"), 403, 'forbidden');

    $laptop = $ana->tokens()->where('name', 'laptop')->first();
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $token = $ana->createToken('laptop2');
    Run::whereKey($runId)->update(['token_id' => $token->accessToken->id]);
    $this->withToken($token->plainTextToken);
    expectError($this->postJson("/api/v1/runs/{$runId}/approvals", ['toolUseId' => 'nope', 'decision' => 'allow']), 422, 'invalid_request');
    $this->postJson("/api/v1/runs/{$runId}/approvals", ['toolUseId' => 'toolu_1', 'decision' => 'allow-always'])->assertNoContent();
    expect($c->refresh()->status)->toBe('running');
    expect(ToolApproval::first())->toMatchArray(['decision' => 'allow-always', 'tool_name' => 'write_file', 'decided_by' => $ana->id]);

    $this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 2, 'events' => [
        ['type' => 'run.tool_result', 'runId' => $runId, 'toolUseId' => 'toolu_1', 'output' => [['type' => 'text', 'text' => 'written']], 'isError' => false, 'durationMs' => 4],
        ['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'Done.'],
    ]])->assertOk();
    expect(broadcasts('run.tool_result')->first()->payload)->toMatchArray(['toolUseId' => 'toolu_1', 'isError' => false]);

    $final = [
        ['type' => 'text', 'text' => 'Writing it.'],
        $toolUse,
        ['type' => 'tool_result', 'toolUseId' => 'toolu_1', 'content' => [['type' => 'text', 'text' => 'written']], 'isError' => false, 'durationMs' => 4],
        ['type' => 'text', 'text' => 'Done.'],
    ];
    $finish = $this->postJson("/api/v1/runs/{$runId}/finish", [
        'status' => 'complete', 'stopReason' => 'end_turn', 'content' => $final, 'error' => null, 'usage' => usageInput(),
    ])->assertOk();
    expectContract($finish, 'HubRunFinishResult');
    expect($finish->json('message.status'))->toBe('complete');
    expect($finish->json('message.content'))->toEqual($final);
    expect($finish->json('conversation.status'))->toBe('idle');

    $record = UsageRecord::sole();
    expect($record)->toMatchArray(['user_id' => $ana->id, 'agent_id' => $c->agent_id, 'model' => 'claude-haiku-4-5', 'output_tokens' => 5]);
    expect($meter->recorded)->toBe([$record->id]);
    Event::assertDispatched(RunCompleted::class, fn (RunCompleted $e) => $e->status === 'complete' && $e->usage?->id === $record->id);
    expect(broadcasts('run.done')->first()->payload['stopReason'])->toBe('end_turn');
    expect(broadcasts('message.updated')->last()->payload['message']['status'])->toBe('complete');
    expect(broadcasts('conversation.updated')->last()->payload['runner'])->toBeNull();

    expectError($this->postJson("/api/v1/runs/{$runId}/finish", ['status' => 'complete', 'content' => [], 'error' => null, 'usage' => null]), 422, 'invalid_request');
    expect($laptop)->not->toBeNull();
});

it('retries a failed reply in place, and only a failed one', function () {
    [, $c, $ana] = runSetup();
    actingAsToken($ana);
    [$runId] = startRun($c);
    $this->postJson("/api/v1/runs/{$runId}/finish", [
        'status' => 'error', 'content' => [['type' => 'text', 'text' => 'Part']],
        'error' => ['code' => 'rate_limited', 'message' => '429', 'retryable' => true], 'usage' => null,
    ])->assertOk()->assertJsonPath('conversation.status', 'error');
    expect(broadcasts('run.error')->first()->payload)->toMatchArray(['code' => 'rate_limited', 'retryable' => true]);

    $reply = Message::where('role', 'assistant')->sole();
    [, $retry] = startRun($c, null);
    $retry->assertCreated();
    expect($retry->json('userMessage'))->toBeNull();
    expect($retry->json('reply'))->toMatchArray(['id' => $reply->id, 'seq' => $reply->seq, 'status' => 'streaming', 'content' => [], 'error' => null]);
    expect(broadcasts('message.updated')->last()->payload['message']['status'])->toBe('streaming');

    $this->postJson('/api/v1/runs/'.$retry->json('runId').'/finish', ['status' => 'complete', 'content' => [['type' => 'text', 'text' => 'Ok']], 'error' => null, 'usage' => null])->assertOk();
    expectError(startRun($c, null)[1], 422, 'invalid_request');
});

it('ends a run whose desktop went quiet, and frees the conversation', function () {
    [, $c, $ana, $bea] = runSetup();
    actingAsToken($ana);
    [$runId] = startRun($c);
    $this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 1, 'events' => [['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'Half']]]);
    Run::whereKey($runId)->update(['lease_expires_at' => now()->subSecond()]);

    expectError($this->postJson("/api/v1/runs/{$runId}/events", ['batch' => 2, 'events' => [['type' => 'run.text_delta', 'runId' => $runId, 'text' => 'x']]]), 409, 'run_expired');
    $reply = Message::where('role', 'assistant')->sole();
    expect($reply->status)->toBe('error');
    expect($reply->error->code)->toBe('interrupted');
    expect(Blocks::text($reply->content))->toBe('Half');
    expect($c->refresh()->status)->toBe('error');
    expectError($this->postJson("/api/v1/runs/{$runId}/finish", ['status' => 'complete', 'content' => [], 'error' => null, 'usage' => null]), 409, 'run_expired');

    actingAsToken($bea);
    [$next] = startRun($c);
    Run::whereKey($next)->update(['lease_expires_at' => now()->subSecond()]);
    $this->artisan('hub:expire-runs')->assertSuccessful();
    expect(Run::find($next)->status)->toBe('expired');
    expect(broadcasts('run.error')->pluck('payload.code')->unique()->all())->toBe(['interrupted']);
    // The lock is free: Ana can run it again.
    actingAsToken($ana);
    startRun($c)[1]->assertCreated();
});

it('renews the lease with heartbeats', function () {
    [, $c, $ana] = runSetup();
    actingAsToken($ana);
    [$runId] = startRun($c);
    Run::whereKey($runId)->update(['lease_expires_at' => now()->addSecond()]);
    $this->postJson("/api/v1/runs/{$runId}/heartbeat")->assertNoContent();
    expect(Run::find($runId)->lease_expires_at->greaterThan(now()->addSeconds(20)))->toBeTrue();
});

it('asks the running desktop to stop: its member or an admin may, others may not', function () {
    [$ws, $c, $ana, $bea] = runSetup();
    $cid = joinWorkspace($ws, user('Cid'));
    actingAsToken($cid);
    $this->postJson("/api/v1/conversations/{$c->id}/cancel")->assertNoContent();
    expect(broadcasts('run.cancel_requested'))->toHaveCount(0);

    actingAsToken($bea);
    [$runId] = startRun($c);
    actingAsToken($cid);
    expectError($this->postJson("/api/v1/conversations/{$c->id}/cancel"), 403, 'forbidden');
    actingAsToken($ana);
    $this->postJson("/api/v1/conversations/{$c->id}/cancel")->assertNoContent();
    expect(broadcasts('run.cancel_requested')->first()->payload)->toMatchArray(['runId' => $runId, 'byUserId' => $ana->id]);
});

it('asks the run gate before starting', function () {
    app()->instance(RunGate::class, new class implements RunGate
    {
        public function refuse(User $user, Conversation $conversation): ?string
        {
            return 'Out of runs this month';
        }
    });
    [, $c, $ana] = runSetup();
    actingAsToken($ana);
    expectError(startRun($c)[1], 403, 'forbidden')->assertJsonPath('error.message', 'Out of runs this month');
});
