<?php

use App\Models\User;

it('describes itself without auth', function () {
    $response = $this->getJson('/api/v1/meta')->assertOk();
    expectContract($response, 'HubMeta');
    $pinned = json_decode(file_get_contents(resource_path('contract/VERSION')), true)['tag'];
    expect($response->json())->toMatchArray([
        'apiVersion' => 1,
        'edition' => 'community',
        'contractVersion' => $pinned,
    ]);
    expect($response->json('capabilities'))->toBe(['execution' => false, 'registration' => 'open']);
});

it('announces the realtime endpoint when Reverb is the broadcaster', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'app-key',
        'hub.realtime' => ['host' => 'hub.example.com', 'port' => 443, 'scheme' => 'https'],
    ]);
    $response = $this->getJson('/api/v1/meta');
    expectContract($response, 'HubMeta');
    expect($response->json('realtime'))->toBe(['key' => 'app-key', 'host' => 'hub.example.com', 'port' => 443, 'scheme' => 'https']);
});

it('registers, signs in with a device token and signs out', function () {
    $register = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ana', 'email' => 'Ana@Example.com', 'password' => 'correct horse', 'deviceName' => 'laptop',
    ])->assertCreated();
    expectContract($register, 'HubAuthResult');

    expectError($this->postJson('/api/v1/auth/register', [
        'name' => 'Ana 2', 'email' => 'ana@example.com', 'password' => 'correct horse', 'deviceName' => 'x',
    ]), 409, 'email_taken');

    expectError($this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com', 'password' => 'wrong', 'deviceName' => 'desk',
    ]), 401, 'invalid_credentials');

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'ANA@example.com', 'password' => 'correct horse', 'deviceName' => 'desk',
    ])->assertOk();
    expectContract($login, 'HubAuthResult');
    $token = $login->json('token');

    app('auth')->forgetGuards();
    expectContract($this->withToken($token)->getJson('/api/v1/me')->assertOk(), 'HubUser');
    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
    app('auth')->forgetGuards();
    expectError($this->withToken($token)->getJson('/api/v1/me'), 401, 'hub_auth_required');
    // The other device's token still works.
    app('auth')->forgetGuards();
    $this->withToken($register->json('token'))->getJson('/api/v1/me')->assertOk();
});

it('validates bodies against the contract', function () {
    $response = $this->postJson('/api/v1/auth/register', ['name' => 'x', 'email' => 'not-an-email', 'password' => 'short', 'deviceName' => 'd']);
    expectError($response, 422, 'invalid_request');
    expectError($this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{nope'), 422, 'invalid_request');
});

it('takes invitation-only registration, except for the first account', function () {
    config(['hub.registration' => 'invite-only']);
    $body = fn (string $email) => ['name' => 'N', 'email' => $email, 'password' => 'long enough', 'deviceName' => 'd'];
    $this->postJson('/api/v1/auth/register', $body('first@example.com'))->assertCreated();
    expectError($this->postJson('/api/v1/auth/register', $body('second@example.com')), 403, 'forbidden');
    expect(User::count())->toBe(1);
});

it('signs the web UI in with a session', function () {
    user('Bea', 'bea@example.com');
    $this->withHeader('Origin', 'http://localhost')
        ->postJson('/login', ['email' => 'bea@example.com', 'password' => 'secret-password', 'deviceName' => 'browser'])
        ->assertOk();
    $this->withHeader('Origin', 'http://localhost')->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'bea@example.com');
});

it('refuses protected endpoints without a token', function () {
    expectError($this->getJson('/api/v1/workspaces'), 401, 'hub_auth_required');
});
