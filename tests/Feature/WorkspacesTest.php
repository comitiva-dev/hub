<?php

use App\Events\MemberAdded;
use App\Events\MemberRemoved;
use App\Events\WorkspaceCreated;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Workspace;
use App\Realtime\HubBroadcast;
use Illuminate\Support\Facades\Event;

it('creates a workspace owned by its creator and lists it with the caller role', function () {
    Event::fake([WorkspaceCreated::class, HubBroadcast::class]);
    $ana = user('Ana');
    actingAsToken($ana);
    $created = $this->postJson('/api/v1/workspaces', ['name' => '  Research  '])->assertCreated();
    expectContract($created, 'Workspace');
    expect($created->json())->toMatchArray(['name' => 'Research', 'role' => 'owner']);
    Event::assertDispatched(WorkspaceCreated::class);
    expect(broadcasts('workspace.joined'))->toHaveCount(1);

    expectContract($this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1), 'Workspace', list: true);
});

it('hides workspaces from people outside them', function () {
    $ws = workspace(user('Ana'));
    actingAsToken(user('Eve'));
    expectError($this->getJson("/api/v1/workspaces/{$ws->id}"), 404, 'not_found');
    expectError($this->getJson("/api/v1/workspaces/{$ws->id}/agents"), 404, 'not_found');
    expectError($this->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'x']), 404, 'not_found');
});

it('lets admins rename, owners delete, and members neither', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'), 'admin');
    $cid = joinWorkspace($ws, user('Cid'));

    actingAsToken($cid);
    expectError($this->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'Mine']), 403, 'forbidden');
    actingAsToken($bea);
    expectContract($this->patchJson("/api/v1/workspaces/{$ws->id}", ['name' => 'Lab'])->assertOk(), 'Workspace');
    expect(broadcasts('workspace.updated'))->toHaveCount(3);
    expectError($this->deleteJson("/api/v1/workspaces/{$ws->id}"), 403, 'forbidden');
    actingAsToken($ana);
    $this->deleteJson("/api/v1/workspaces/{$ws->id}")->assertNoContent();
    expect(Workspace::find($ws->id))->toBeNull();
    expect(broadcasts('workspace.left'))->toHaveCount(3);
});

it('changes roles as owner only, and always keeps an owner', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'), 'admin');

    actingAsToken($bea);
    expectError($this->patchJson("/api/v1/workspaces/{$ws->id}/members/{$bea->id}", ['role' => 'owner']), 403, 'forbidden');
    actingAsToken($ana);
    expectError($this->patchJson("/api/v1/workspaces/{$ws->id}/members/{$ana->id}", ['role' => 'member']), 422, 'invalid_request');
    expectContract($this->patchJson("/api/v1/workspaces/{$ws->id}/members/{$bea->id}", ['role' => 'owner'])->assertOk(), 'Member');
    $this->patchJson("/api/v1/workspaces/{$ws->id}/members/{$ana->id}", ['role' => 'member'])->assertOk();
    expect($ws->membershipOf($ana)->role)->toBe('member');
    expect(broadcasts('member.updated'))->toHaveCount(2);
    expectContract($this->getJson("/api/v1/workspaces/{$ws->id}/members")->assertOk()->assertJsonCount(2), 'Member', list: true);
});

it('lets admins remove members but not admins, and anyone but the last owner leave', function () {
    Event::fake([MemberRemoved::class, HubBroadcast::class]);
    $ana = user('Ana');
    $ws = workspace($ana);
    $bea = joinWorkspace($ws, user('Bea'), 'admin');
    $cid = joinWorkspace($ws, user('Cid'));
    $dan = joinWorkspace($ws, user('Dan'), 'admin');

    actingAsToken($bea);
    expectError($this->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$dan->id}"), 403, 'forbidden');
    $this->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$cid->id}")->assertNoContent();
    actingAsToken($ana);
    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/leave"), 422, 'invalid_request');
    actingAsToken($dan);
    $this->postJson("/api/v1/workspaces/{$ws->id}/leave")->assertNoContent();

    expect(Membership::where('workspace_id', $ws->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$ana->id, $bea->id])->sort()->values()->all());
    Event::assertDispatchedTimes(MemberRemoved::class, 2);
    expect(broadcasts('workspace.left')->pluck('payload.workspaceId')->unique()->all())->toBe([$ws->id]);
});

it('invites by link: admins create it, the invited email accepts it once', function () {
    Event::fake([MemberAdded::class, HubBroadcast::class]);
    $ana = user('Ana');
    $ws = workspace($ana);
    $cid = joinWorkspace($ws, user('Cid'));
    $bea = user('Bea', 'bea@example.com');

    actingAsToken($cid);
    expectError($this->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'bea@example.com']), 403, 'forbidden');
    actingAsToken($ana);
    $created = $this->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'BEA@example.com', 'role' => 'admin'])->assertCreated();
    expectContract($created, 'InvitationCreated');
    $token = $created->json('token');
    expect($created->json('url'))->toEndWith("/invite/{$token}");
    expect(Invitation::first()->token_hash)->not->toBe($token);
    expectContract($this->getJson("/api/v1/workspaces/{$ws->id}/invitations")->assertJsonCount(1), 'Invitation', list: true);

    app('auth')->forgetGuards();
    $this->flushHeaders();
    expectContract($this->getJson("/api/v1/invitations/{$token}")->assertOk(), 'InvitationPreview');

    actingAsToken(user('Eve', 'eve@example.com'));
    expectError($this->postJson("/api/v1/invitations/{$token}/accept"), 403, 'invitation_invalid');
    actingAsToken($bea);
    expectContract($this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->assertJsonPath('role', 'admin'), 'Workspace');
    Event::assertDispatched(MemberAdded::class);
    expect(broadcasts('member.added'))->toHaveCount(1);
    expect(broadcasts('workspace.joined')->first()->payload['workspace']['id'])->toBe($ws->id);

    expectError($this->postJson("/api/v1/invitations/{$token}/accept"), 404, 'invitation_invalid');
    expectError($this->getJson('/api/v1/invitations/not-a-token'), 404, 'invitation_invalid');
});

it('refuses expired invitations and accepts one while registering', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    actingAsToken($ana);
    $expired = $this->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'old@example.com'])->json('token');
    Invitation::query()->update(['expires_at' => now()->subMinute()]);
    $fresh = $this->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'new@example.com'])->json('token');

    app('auth')->forgetGuards();
    $this->flushHeaders();
    config(['hub.registration' => 'invite-only']);
    $body = fn (string $email, string $token) => [
        'name' => 'N', 'email' => $email, 'password' => 'long enough', 'deviceName' => 'd', 'invitationToken' => $token,
    ];
    expectError($this->postJson('/api/v1/auth/register', $body('old@example.com', $expired)), 404, 'invitation_invalid');
    expectError($this->postJson('/api/v1/auth/register', $body('other@example.com', $fresh)), 403, 'invitation_invalid');
    $this->postJson('/api/v1/auth/register', $body('new@example.com', $fresh))->assertCreated();
    expect($ws->memberships()->count())->toBe(2);
});

it('lets owners and admins revoke an invitation', function () {
    $ana = user('Ana');
    $ws = workspace($ana);
    actingAsToken($ana);
    $id = $this->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'x@example.com'])->json('id');
    $this->deleteJson("/api/v1/invitations/{$id}")->assertNoContent();
    expect(Invitation::count())->toBe(0);
});
