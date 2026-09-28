<?php

use App\Support\Iso;

it('creates conversations for workspace agents only and announces them', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $agent = sharedAgent($ws);
    $foreign = sharedAgent(workspace($ana, 'Other'));
    actingAsToken($ana);

    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/conversations", ['agentId' => $foreign->id]), 404, 'not_found');
    $created = $this->postJson("/api/v1/workspaces/{$ws->id}/conversations", ['agentId' => $agent->id])->assertCreated();
    expectContract($created, 'Conversation');
    expect($created->json())->toMatchArray(['title' => null, 'status' => 'idle', 'harnessSessionId' => null, 'archived' => false]);
    expect(broadcasts('conversation.created'))->toHaveCount(1);
});

it('lists newest first with unread replies per member and filters by agent and archive', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'));
    $agent = sharedAgent($ws);
    $old = conversationIn($ws, $agent, ['last_activity_at' => Iso::now()->subHour()]);
    $new = conversationIn($ws, $agent);
    conversationIn($ws, null, ['archived' => true]);
    messageIn($new, 1, 'user', 'Hi');
    messageIn($new, 2, 'assistant', 'Hello');
    messageIn($new, 3, 'user', 'More');
    messageIn($new, 4, 'assistant', 'Streaming', 'streaming');

    actingAsToken($ana);
    $list = $this->getJson("/api/v1/workspaces/{$ws->id}/conversations?agentId={$agent->id}")->assertOk();
    expectContract($list, 'HubConversationSummary', list: true);
    expect(array_column(array_column($list->json(), 'conversation'), 'id'))->toBe([$new->id, $old->id]);
    expect($list->json('0.unread'))->toBe(1);

    $this->postJson("/api/v1/conversations/{$new->id}/read")->assertNoContent();
    expect($this->getJson("/api/v1/workspaces/{$ws->id}/conversations")->json('0.unread'))->toBe(0);
    actingAsToken($bea);
    expect($this->getJson("/api/v1/workspaces/{$ws->id}/conversations")->json('0.unread'))->toBe(1);
    expect($this->getJson("/api/v1/workspaces/{$ws->id}/conversations?archived=true")->json())->toHaveCount(1);
});

it('renames, lets a generated title replace only the placeholder, and archives', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $c = conversationIn($ws, null, ['title' => 'Hi there', 'title_source' => 'placeholder']);
    actingAsToken($ana);

    $this->patchJson("/api/v1/conversations/{$c->id}", ['title' => 'Greetings', 'titleSource' => 'auto'])->assertJsonPath('title', 'Greetings');
    $this->patchJson("/api/v1/conversations/{$c->id}", ['title' => 'Mine'])->assertJsonPath('title', 'Mine');
    $this->patchJson("/api/v1/conversations/{$c->id}", ['title' => 'Late', 'titleSource' => 'auto'])->assertJsonPath('title', 'Mine');
    expectContract($this->patchJson("/api/v1/conversations/{$c->id}", ['archived' => true])->assertOk(), 'Conversation')
        ->assertJsonPath('archived', true);
    expect(broadcasts('conversation.updated'))->toHaveCount(4);
});

it('pages messages oldest first at the conversation rev', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $c = conversationIn($ws, null, ['rev' => 7]);
    foreach (range(1, 5) as $seq) {
        messageIn($c, $seq, $seq % 2 ? 'user' : 'assistant', "m{$seq}");
    }
    actingAsToken($ana);
    $page = $this->getJson("/api/v1/conversations/{$c->id}/messages?limit=2")->assertOk();
    expectContract($page, 'MessagePage');
    expect(array_column($page->json('messages'), 'seq'))->toBe([4, 5]);
    expect($page->json())->toMatchArray(['hasMore' => true, 'rev' => 7]);
    $older = $this->getJson("/api/v1/conversations/{$c->id}/messages?limit=10&beforeSeq=4")->json();
    expect(array_column($older['messages'], 'seq'))->toBe([1, 2, 3]);
    expect($older['hasMore'])->toBeFalse();
    expectError($this->getJson("/api/v1/conversations/{$c->id}/messages?limit=0"), 422, 'invalid_request');

    actingAsToken(user('Eve'));
    expectError($this->getJson("/api/v1/conversations/{$c->id}/messages"), 404, 'not_found');
});
