<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\WorkspaceCreated;
use App\Exceptions\HubException;
use App\Extension\AuditSink;
use App\Extension\PlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Workspace;
use App\Realtime\Realtime;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function __construct(private readonly Realtime $realtime) {}

    /** The caller's workspaces, oldest first. */
    public function index(Request $request): JsonResponse
    {
        $memberships = $this->user($request)->memberships()->with('workspace')->orderBy('created_at')->get();

        return response()->json($memberships->map(
            fn (Membership $m) => Present::workspace($m->workspace, $m->role)
        )->values());
    }

    public function store(Request $request, PlanLimits $limits): JsonResponse
    {
        $user = $this->user($request);
        $body = $this->body($request, 'WorkspaceDraft');
        $max = $limits->maxWorkspaces($user);
        if ($max !== null && $user->memberships()->where('role', 'owner')->count() >= $max) {
            throw HubException::forbidden('Your plan does not allow more workspaces');
        }

        $workspace = DB::transaction(function () use ($user, $body) {
            $workspace = Workspace::create(['name' => trim($body->name), 'created_by' => $user->id]);
            Membership::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
            $this->realtime->toUser($user->id, [
                'type' => 'workspace.joined',
                'workspace' => Present::workspace($workspace, 'owner'),
            ]);

            return $workspace;
        });
        WorkspaceCreated::dispatch($workspace, $user);

        return response()->json(Present::workspace($workspace, 'owner'), 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $membership = $this->membership($request, $workspace);

        return response()->json(Present::workspace($workspace, $membership->role));
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $membership = $this->admin($request, $workspace);
        $body = $this->body($request, 'WorkspaceDraft');
        DB::transaction(function () use ($workspace, $body) {
            $workspace->update(['name' => trim($body->name)]);
            foreach ($workspace->memberships()->get() as $m) {
                $this->realtime->toUser($m->user_id, [
                    'type' => 'workspace.updated',
                    'workspace' => Present::workspace($workspace, $m->role),
                ]);
            }
        });

        return response()->json(Present::workspace($workspace, $membership->role));
    }

    /** Owners only. Everything in it goes with it. */
    public function destroy(Request $request, Workspace $workspace, AuditSink $audit): Response
    {
        $this->owner($request, $workspace);
        DB::transaction(function () use ($workspace) {
            foreach ($workspace->memberships()->pluck('user_id') as $userId) {
                $this->realtime->toUser($userId, ['type' => 'workspace.left', 'workspaceId' => $workspace->id]);
            }
            $workspace->delete();
        });
        $audit->record('workspace.deleted', $this->user($request), null, ['workspaceId' => $workspace->id, 'name' => $workspace->name]);

        return response()->noContent();
    }
}
