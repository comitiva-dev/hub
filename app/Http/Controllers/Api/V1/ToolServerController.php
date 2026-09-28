<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ToolServer;
use App\Models\Workspace;
use App\Realtime\Realtime;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Workspace MCP servers: http only (stdio servers exist on one machine).
 * A secret header is `{ secretRef: 'member' }` here; each member keeps its
 * value on their own desktop. Admins manage them; members use them.
 */
class ToolServerController extends Controller
{
    public function __construct(private readonly Realtime $realtime) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $servers = $workspace->toolServers()->orderBy('created_at')->get();

        return response()->json($servers->map(fn (ToolServer $s) => Present::toolServer($s))->values());
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);
        $body = $this->body($request, 'WorkspaceToolServerDraft');
        $server = DB::transaction(function () use ($request, $workspace, $body) {
            $server = ToolServer::create([
                'workspace_id' => $workspace->id,
                'name' => trim($body->name),
                'url' => $body->url,
                'headers' => $body->headers ?? new stdClass,
                'enabled' => $body->enabled ?? true,
                'created_by' => $this->user($request)->id,
            ]);
            $this->realtime->toWorkspace($workspace, ['type' => 'tool_server.created', 'toolServer' => Present::toolServer($server)]);

            return $server;
        });

        return response()->json(Present::toolServer($server), 201);
    }

    public function update(Request $request, ToolServer $toolServer): JsonResponse
    {
        $this->admin($request, $toolServer->workspace);
        $body = $this->body($request, 'WorkspaceToolServerPatch');
        DB::transaction(function () use ($toolServer, $body) {
            foreach (['name', 'url', 'headers', 'enabled'] as $field) {
                if (property_exists($body, $field)) {
                    $toolServer->{$field} = $field === 'name' ? trim($body->name) : $body->{$field};
                }
            }
            $toolServer->save();
            $this->realtime->toWorkspace($toolServer->workspace_id, ['type' => 'tool_server.updated', 'toolServer' => Present::toolServer($toolServer)]);
        });

        return response()->json(Present::toolServer($toolServer));
    }

    /** Agents that used it lose it; they are announced as updated. */
    public function destroy(Request $request, ToolServer $toolServer): Response
    {
        $this->admin($request, $toolServer->workspace);
        DB::transaction(function () use ($toolServer) {
            $agentIds = DB::table('agent_tool_servers')->where('tool_server_id', $toolServer->id)->pluck('agent_id');
            $toolServer->delete();
            $this->realtime->toWorkspace($toolServer->workspace_id, [
                'type' => 'tool_server.deleted',
                'workspaceId' => $toolServer->workspace_id,
                'toolServerId' => $toolServer->id,
            ]);
            foreach (Agent::with('toolServers')->whereIn('id', $agentIds)->get() as $agent) {
                $this->realtime->toWorkspace($agent->workspace_id, ['type' => 'agent.updated', 'agent' => Present::agent($agent)]);
            }
        });

        return response()->noContent();
    }
}
