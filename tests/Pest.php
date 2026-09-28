<?php

use App\Contract\Contract;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Realtime\HubBroadcast;
use App\Support\Iso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Feature tests run on Postgres (docker compose: database hub_test). Every
 * broadcast is captured, and after each test every one of them must match
 * the contract's HubEvent schema.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Event::fake([HubBroadcast::class]);
    })
    ->afterEach(function () {
        foreach (broadcasts() as $event) {
            $errors = app(Contract::class)->errors('HubEvent', Contract::toJson($event->payload));
            expect($errors)->toBeNull("broadcast {$event->type()} does not match HubEvent: {$errors}");
        }
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** A user with a known password (`secret-password`). */
function user(string $name = 'Ana', ?string $email = null): User
{
    return User::create([
        'name' => $name,
        'email' => $email ?? strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com',
        'password' => 'secret-password',
    ]);
}

function workspace(User $owner, string $name = 'Team'): Workspace
{
    $workspace = Workspace::create(['name' => $name, 'created_by' => $owner->id]);
    Membership::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);

    return $workspace;
}

function joinWorkspace(Workspace $workspace, User $user, string $role = 'member'): User
{
    Membership::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

/**
 * Sends the next requests with a fresh personal access token of `$user`,
 * forgetting whoever the guard remembered from earlier requests.
 */
function actingAsToken(User $user, string $device = 'test'): TestCase
{
    /** @var TestCase $test */
    $test = test();
    app('auth')->forgetGuards();
    $test->flushHeaders();

    return $test->withToken($user->createToken($device)->plainTextToken);
}

/** Asserts a JSON response body (or each item of a list) matches a contract schema. */
function expectContract(TestResponse $response, string $schema, bool $list = false): TestResponse
{
    $body = json_decode((string) $response->getContent());
    foreach ($list ? $body : [$body] as $item) {
        $errors = app(Contract::class)->errors($schema, $item);
        expect($errors)->toBeNull("{$schema}: {$errors}");
    }

    return $response;
}

function expectError(TestResponse $response, int $status, string $code): TestResponse
{
    $response->assertStatus($status);
    expect($response->json('error.code'))->toBe($code);
    expectContract($response, 'HubErrorBody');

    return $response;
}

/** @return Collection<int, HubBroadcast> */
function broadcasts(?string $type = null): Collection
{
    return Event::dispatched(HubBroadcast::class)
        ->map(fn (array $args) => $args[0])
        ->filter(fn (HubBroadcast $e) => $type === null || $e->type() === $type)
        ->values();
}

function sharedAgent(Workspace $ws): Agent
{
    return Agent::create([
        'workspace_id' => $ws->id, 'name' => 'Researcher', 'avatar' => (object) ['color' => 'indigo'],
        'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'role' => '', 'params' => new stdClass,
        'permission_policy' => 'ask', 'tags' => [],
    ]);
}

function conversationIn(Workspace $ws, ?Agent $agent = null, array $attrs = []): Conversation
{
    return Conversation::create([
        'workspace_id' => $ws->id, 'agent_id' => ($agent ?? sharedAgent($ws))->id, 'status' => 'idle',
        'archived' => false, 'last_activity_at' => Iso::now(), ...$attrs,
    ]);
}

function messageIn(Conversation $c, int $seq, string $role, string $text, string $status = 'complete'): Message
{
    return Message::create([
        'conversation_id' => $c->id, 'seq' => $seq, 'role' => $role,
        'content' => [(object) ['type' => 'text', 'text' => $text]], 'status' => $status,
        'search_text' => $text,
    ]);
}
