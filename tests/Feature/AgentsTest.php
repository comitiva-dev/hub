<?php

use App\Models\Agent;
use App\Models\ToolServer;

function agentDraft(array $overrides = []): array
{
    return [
        'name' => 'Researcher',
        'avatar' => ['color' => 'indigo', 'emoji' => '🔎'],
        'provider' => 'anthropic',
        'model' => 'claude-haiku-4-5',
        ...$overrides,
    ];
}

it('shares an agent by provider and model, with defaults, and keeps empty objects as objects', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    actingAsToken($ana);
    $response = $this->postJson("/api/v1/workspaces/{$ws->id}/agents", agentDraft([
        'connectionId' => '01J00000000000000000000001',
        'roots' => [['path' => '/home/ana', 'mode' => 'read']],
    ]))->assertCreated();
    expectContract($response, 'SharedAgent');
    expect($response->json())->toMatchArray([
        'role' => '', 'permissionPolicy' => 'ask', 'tags' => [], 'toolServerIds' => [], 'createdBy' => $ana->id,
    ]);
    expect($response->getContent())->toContain('"params":{}');
    expect($response->json())->not->toHaveKeys(['connectionId', 'roots']);
    expect(broadcasts('agent.created'))->toHaveCount(1);
    expectContract($this->getJson("/api/v1/workspaces/{$ws->id}/agents")->assertJsonCount(1), 'SharedAgent', list: true);
});

it('refuses tool servers from another workspace and keeps the order given', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $other = workspace($ana, 'Other');
    $a = ToolServer::create(['workspace_id' => $ws->id, 'name' => 'A', 'url' => 'https://a.example/mcp', 'headers' => new stdClass, 'enabled' => true]);
    $b = ToolServer::create(['workspace_id' => $ws->id, 'name' => 'B', 'url' => 'https://b.example/mcp', 'headers' => new stdClass, 'enabled' => true]);
    $foreign = ToolServer::create(['workspace_id' => $other->id, 'name' => 'F', 'url' => 'https://f.example/mcp', 'headers' => new stdClass, 'enabled' => true]);
    actingAsToken($ana);

    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/agents", agentDraft(['toolServerIds' => [$foreign->id]])), 422, 'invalid_request');
    $id = $this->postJson("/api/v1/workspaces/{$ws->id}/agents", agentDraft(['toolServerIds' => [$b->id, $a->id]]))
        ->assertCreated()->assertJsonPath('toolServerIds', [$b->id, $a->id])->json('id');
    $this->patchJson("/api/v1/agents/{$id}", ['toolServerIds' => [$a->id]])->assertJsonPath('toolServerIds', [$a->id]);
});

it('lets the creator and admins edit or delete an agent, and no one else', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'));
    $cid = joinWorkspace($ws, user('Cid'));

    actingAsToken($bea);
    $id = $this->postJson("/api/v1/workspaces/{$ws->id}/agents", agentDraft())->json('id');
    expectContract($this->patchJson("/api/v1/agents/{$id}", ['role' => 'Be brief.', 'model' => '  '])->assertOk(), 'SharedAgent')
        ->assertJsonPath('model', null)->assertJsonPath('role', 'Be brief.');
    actingAsToken($cid);
    expectError($this->patchJson("/api/v1/agents/{$id}", ['name' => 'Mine']), 403, 'forbidden');
    expectError($this->deleteJson("/api/v1/agents/{$id}"), 403, 'forbidden');
    expectContract($this->getJson("/api/v1/agents/{$id}")->assertOk(), 'SharedAgent');
    actingAsToken($ana);
    $this->patchJson("/api/v1/agents/{$id}", ['name' => 'Renamed'])->assertOk();
    $this->deleteJson("/api/v1/agents/{$id}")->assertNoContent();
    expect(Agent::count())->toBe(0);
    expect(broadcasts('agent.updated'))->toHaveCount(2);
    expect(broadcasts('agent.deleted')->first()->payload)->toMatchArray(['agentId' => $id, 'workspaceId' => $ws->id]);
});

it('keeps workspace tool servers to http and never stores a secret header value', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $cid = joinWorkspace($ws, user('Cid'));
    actingAsToken($cid);
    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/tool-servers", ['name' => 'x', 'url' => 'https://x.example']), 403, 'forbidden');

    actingAsToken($ana);
    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/tool-servers", ['name' => 'x', 'url' => 'file:///etc/passwd']), 422, 'invalid_request');
    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/tool-servers", [
        'name' => 'x', 'url' => 'https://x.example', 'headers' => ['Authorization' => ['secret' => 'sk-live']],
    ]), 422, 'invalid_request');
    $created = $this->postJson("/api/v1/workspaces/{$ws->id}/tool-servers", [
        'name' => 'Search',
        'url' => 'https://mcp.example.com/mcp',
        'headers' => ['Authorization' => ['secretRef' => 'member'], 'X-Team' => ['value' => 'research']],
    ])->assertCreated();
    expectContract($created, 'WorkspaceToolServer');
    $id = $created->json('id');
    expectContract($this->patchJson("/api/v1/tool-servers/{$id}", ['enabled' => false])->assertOk(), 'WorkspaceToolServer')
        ->assertJsonPath('enabled', false);
    expectContract($this->getJson("/api/v1/workspaces/{$ws->id}/tool-servers")->assertJsonCount(1), 'WorkspaceToolServer', list: true);

    actingAsToken($cid);
    $agentId = $this->postJson("/api/v1/workspaces/{$ws->id}/agents", agentDraft(['toolServerIds' => [$id]]))->json('id');
    actingAsToken($ana);
    $this->deleteJson("/api/v1/tool-servers/{$id}")->assertNoContent();
    $this->getJson("/api/v1/agents/{$agentId}")->assertJsonPath('toolServerIds', []);
    expect(broadcasts('tool_server.deleted'))->toHaveCount(1);
    expect(broadcasts('agent.updated')->last()->payload['agent']['toolServerIds'])->toBe([]);
});
