<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MemberRemoved;
use App\Exceptions\HubException;
use App\Extension\AuditSink;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Realtime\Realtime;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Owners change roles (a workspace always keeps an owner); admins remove
 * members; anyone but the last owner can leave.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly Realtime $realtime,
        private readonly AuditSink $audit,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $members = $workspace->memberships()->with('user')->orderBy('created_at')->get();

        return response()->json($members->map(fn (Membership $m) => Present::member($m))->values());
    }

    public function update(Request $request, Workspace $workspace, User $user): JsonResponse
    {
        $this->owner($request, $workspace);
        $body = $this->body($request, 'MemberPatch');
        $membership = DB::transaction(function () use ($workspace, $user, $body) {
            $membership = $this->target($workspace, $user);
            if ($membership->isOwner() && $body->role !== 'owner') {
                $this->keepAnOwner($workspace);
            }
            $membership->update(['role' => $body->role]);
            $this->announce($workspace, $membership);

            return $membership;
        });
        $this->audit->record('member.role_changed', $this->user($request), $workspace, ['userId' => $user->id, 'role' => $body->role]);

        return response()->json(Present::member($membership));
    }

    public function destroy(Request $request, Workspace $workspace, User $user): Response
    {
        $actor = $this->admin($request, $workspace);
        DB::transaction(function () use ($workspace, $user, $actor) {
            $membership = $this->target($workspace, $user);
            if (! $actor->isOwner() && $membership->isAdmin()) {
                throw HubException::forbidden('Only owners can remove owners and admins');
            }
            if ($membership->isOwner()) {
                $this->keepAnOwner($workspace);
            }
            $this->remove($workspace, $membership);
        });
        $this->audit->record('member.removed', $this->user($request), $workspace, ['userId' => $user->id]);

        return response()->noContent();
    }

    public function leave(Request $request, Workspace $workspace): Response
    {
        $membership = $this->membership($request, $workspace);
        DB::transaction(function () use ($workspace, $membership) {
            if ($membership->isOwner()) {
                $this->keepAnOwner($workspace);
            }
            $this->remove($workspace, $membership);
        });

        return response()->noContent();
    }

    private function target(Workspace $workspace, User $user): Membership
    {
        $membership = $workspace->memberships()->with('user')->where('user_id', $user->id)->lockForUpdate()->first();
        if ($membership === null) {
            throw HubException::notFound('Not a member of this workspace');
        }

        return $membership;
    }

    /** Called before an owner stops being one. */
    private function keepAnOwner(Workspace $workspace): void
    {
        // Postgres refuses FOR UPDATE with an aggregate, so the locked rows are counted here.
        $owners = count($workspace->memberships()->where('role', 'owner')->lockForUpdate()->pluck('id')->all());
        if ($owners <= 1) {
            throw HubException::invalid('A workspace needs an owner: make someone else owner first');
        }
    }

    private function remove(Workspace $workspace, Membership $membership): void
    {
        $membership->delete();
        $this->realtime->toWorkspace($workspace, [
            'type' => 'member.removed',
            'workspaceId' => $workspace->id,
            'userId' => $membership->user_id,
        ]);
        $this->realtime->toUser($membership->user_id, ['type' => 'workspace.left', 'workspaceId' => $workspace->id]);
        DB::afterCommit(fn () => MemberRemoved::dispatch($workspace, $membership->user_id));
    }

    private function announce(Workspace $workspace, Membership $membership): void
    {
        $this->realtime->toWorkspace($workspace, [
            'type' => 'member.updated',
            'workspaceId' => $workspace->id,
            'member' => Present::member($membership),
        ]);
        $this->realtime->toUser($membership->user_id, [
            'type' => 'workspace.updated',
            'workspace' => Present::workspace($workspace, $membership->role),
        ]);
    }
}
