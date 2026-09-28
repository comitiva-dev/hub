<?php

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => '1',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ],
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
});

function authChannel(string $channel): TestResponse
{
    return test()->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
}

it('lets members listen to their workspace (with presence) and its conversations', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $c = conversationIn($ws);
    actingAsToken($ana);
    $presence = authChannel("presence-workspace.{$ws->id}")->assertOk();
    expect(json_decode($presence->json('channel_data'), true)['user_info'])->toBe(['id' => $ana->id, 'name' => 'Ana']);
    authChannel("private-conversation.{$c->id}")->assertOk();
    authChannel("private-user.{$ana->id}")->assertOk();
});

it('keeps others out', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $c = conversationIn($ws);
    $eve = user('Eve');
    actingAsToken($eve);
    authChannel("presence-workspace.{$ws->id}")->assertForbidden();
    authChannel("private-conversation.{$c->id}")->assertForbidden();
    authChannel("private-user.{$ana->id}")->assertForbidden();
    app('auth')->forgetGuards();
    $this->flushHeaders();
    authChannel("private-user.{$ana->id}")->assertUnauthorized();
    expect(PusherBroadcaster::class)->toBeString();
});
