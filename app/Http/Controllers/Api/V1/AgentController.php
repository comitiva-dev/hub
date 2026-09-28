<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Extension\AuditSink;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Membership;
use App\Models\Workspace;
use App\Realtime\Realtime;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Shared agents. Any member shares one; its creator and admins change or
 * delete it. Each member links it to a local connection on their desktop.
 */
class AgentController extends Controller
{
    private const FIELDS = [
        'name' => 'name',
        'avatar' => 'avatar',
        'provider' => 'provider',
        'model' => 'model',
        'role' => 'role',
        'params' => 'params',
        'permissionPolicy' => 'permission_policy',
        'tags' => 'tags',
    ];

    public function __construct(private readonly Realtime $realtime) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $agents = $workspace->agents()->with('toolServers')->orderBy('created_at')->get();

        return response()->json($agents->map(fn (Agent $a) => Present::agent($a))->values());
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $body = $this->body($request, 'SharedAgentDraft');
        $agent = DB::transaction(function () use ($request, $workspace, $body) {
            $agent = new Agent([
                'workspace_id' => $workspace->id,
                'name' => trim($body->name),
                'avatar' => $body->avatar,
                'provider' => $body->provider,
                'model' => self::model($body->model ?? null),
                'role' => $body->role ?? '',
                'params' => $body->params ?? new stdClass,
                'permission_policy' => $body->permissionPolicy ?? 'ask',
                'tags' => $body->tags ?? [],
                'created_by' => $this->user($request)->id,
            ]);
            $agent->save();
            $this->syncToolServers($agent, $body->toolServerIds ?? []);
            $this->realtime->toWorkspace($workspace, ['type' => 'agent.created', 'agent' => Present::agent($agent)]);

            return $agent;
        });

        return response()->json(Present::agent($agent), 201);
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        $this->membership($request, $agent->workspace);

        return response()->json(Present::agent($agent->load('toolServers')));
    }

    public function update(Request $request, Agent $agent): JsonResponse
    {
        $this->editor($request, $agent);
        $body = $this->body($request, 'SharedAgentPatch');
        DB::transaction(function () use ($agent, $body) {
            foreach (self::FIELDS as $field => $column) {
                if (property_exists($body, $field)) {
                    $value = $body->{$field};
                    $agent->{$column} = match ($field) {
                        'name' => trim($value),
                        'model' => self::model($value),
                        default => $value,
                    };
                }
            }
            $agent->save();
            if (property_exists($body, 'toolServerIds')) {
                $this->syncToolServers($agent, $body->toolServerIds);
            }
            $agent->touch();
            $this->realtime->toWorkspace($agent->workspace_id, ['type' => 'agent.updated', 'agent' => Present::agent($agent)]);
        });

        return response()->json(Present::agent($agent));
    }

    /** Its conversations go with it. */
    public function destroy(Request $request, Agent $agent, AuditSink $audit): Response
    {
        $this->editor($request, $agent);
        DB::transaction(function () use ($agent) {
            $agent->delete();
            $this->realtime->toWorkspace($agent->workspace_id, [
                'type' => 'agent.deleted',
                'workspaceId' => $agent->workspace_id,
                'agentId' => $agent->id,
            ]);
        });
        $audit->record('agent.deleted', $this->user($request), $agent->workspace, ['agentId' => $agent->id, 'name' => $agent->name]);

        return response()->noContent();
    }

    private function editor(Request $request, Agent $agent): Membership
    {
        $membership = $this->membership($request, $agent->workspace);
        if (! $membership->isAdmin() && $agent->created_by !== $membership->user_id) {
            throw HubException::forbidden('Only its creator and admins can change this agent');
        }

        return $membership;
    }

    /** @param  list<string>  $ids */
    private function syncToolServers(Agent $agent, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        $known = $agent->workspace->toolServers()->whereIn('id', $ids)->pluck('id')->all();
        $unknown = array_diff($ids, $known);
        if ($unknown !== []) {
            throw HubException::invalid('Unknown tool server: '.implode(', ', $unknown));
        }
        $agent->toolServers()->sync(array_combine($ids, array_map(fn ($i) => ['position' => $i], array_keys($ids))) ?: []);
        $agent->load('toolServers');
    }

    private static function model(?string $model): ?string
    {
        $model = $model === null ? null : trim($model);

        return $model === '' ? null : $model;
    }
}
