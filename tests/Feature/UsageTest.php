<?php

use App\Models\UsageRecord;

function usageRow(array $attrs): UsageRecord
{
    return UsageRecord::create([
        'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'input_tokens' => 10, 'output_tokens' => 5,
        'cache_read_tokens' => null, 'cache_write_tokens' => null, 'estimated' => false, 'cost_usd' => 0.01,
        'cost_source' => 'table', 'cost_estimated' => false, 'latency_ms' => 100, ...$attrs,
    ]);
}

it('sums a window with the desktop arithmetic, grouped by member, agent and model', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'));
    $agent = sharedAgent($ws);
    $base = ['workspace_id' => $ws->id, 'agent_id' => $agent->id, 'conversation_id' => '01J0000000000000000000CONV'];
    usageRow([...$base, 'user_id' => $ana->id, 'created_at' => '2026-09-10T12:00:00Z']);
    usageRow([...$base, 'user_id' => $bea->id, 'provider' => 'claude-code', 'model' => 'claude-sonnet-4-5', 'cost_usd' => 0.5, 'cost_source' => 'harness', 'created_at' => '2026-09-11T01:30:00Z']);
    usageRow([...$base, 'user_id' => $bea->id, 'cost_usd' => null, 'estimated' => true, 'created_at' => '2026-09-11T02:00:00Z']);
    usageRow([...$base, 'user_id' => $ana->id, 'created_at' => '2026-10-01T00:00:00Z']);
    actingAsToken($ana);

    $q = 'from=2026-09-10T00:00:00Z&to=2026-09-12T00:00:00Z&tzOffsetMinutes=-180';
    $summary = $this->getJson("/api/v1/workspaces/{$ws->id}/usage/summary?{$q}")->assertOk();
    expectContract($summary, 'UsageSummary');
    expect($summary->json('totals'))->toMatchArray([
        'runs' => 3, 'inputTokens' => 30, 'outputTokens' => 15, 'costUsd' => 0.01, 'costUsdCli' => 0.5,
        'anyEstimated' => true, 'anyUnpriced' => true,
    ]);
    expect(array_column($summary->json('byConnection'), 'label'))->toBe(['Bea', 'Ana']);
    expect($summary->json('byAgent.0'))->toMatchArray(['label' => 'Researcher', 'runs' => 3, 'deleted' => false]);
    expect(array_column($summary->json('byModel'), 'key'))->toBe(['anthropic/claude-haiku-4-5', 'claude-code/claude-sonnet-4-5']);

    // Days on the viewer's calendar (UTC-3): 01:30 and 02:00 UTC on the 11th are still the 10th.
    $series = $this->getJson("/api/v1/workspaces/{$ws->id}/usage/timeseries?{$q}")->assertOk();
    expectContract($series, 'UsageBucket', list: true);
    expect(array_column($series->json(), 'day'))->toBe(['2026-09-09', '2026-09-10', '2026-09-11']);
    expect(array_column($series->json(), 'runs'))->toBe([0, 3, 0]);

    expectContract($this->getJson("/api/v1/workspaces/{$ws->id}/usage/records?{$q}")->assertJsonCount(3), 'HubUsageRecord', list: true);
    expectError($this->getJson("/api/v1/workspaces/{$ws->id}/usage/summary"), 422, 'invalid_request');
});

it('totals one conversation', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $c = conversationIn($ws);
    usageRow(['workspace_id' => $ws->id, 'user_id' => $ana->id, 'agent_id' => $c->agent_id, 'conversation_id' => $c->id, 'created_at' => now()]);
    actingAsToken($ana);
    expectContract($this->getJson("/api/v1/conversations/{$c->id}/usage")->assertOk(), 'UsageTotals')->assertJsonPath('runs', 1);
});
