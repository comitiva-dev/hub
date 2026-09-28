<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Support\Invitations;
use App\Support\Iso;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Invitations are links: the hub keeps only the token's hash and sends no
 * mail. The invitee opens the link in Comitiva (or registers with it) and
 * must sign in with the invited email address.
 */
class InvitationController extends Controller
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);
        $open = Invitation::with('inviter')
            ->where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', Iso::now())
            ->orderBy('created_at')
            ->get();

        return response()->json($open->map(fn (Invitation $i) => Present::invitation($i))->values());
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->admin($request, $workspace);
        $body = $this->body($request, 'InvitationDraft');
        $token = Str::random(48);
        $invitation = Invitation::create([
            'workspace_id' => $workspace->id,
            'email' => strtolower(trim($body->email)),
            'role' => $body->role ?? 'member',
            'token_hash' => Invitation::hashToken($token),
            'invited_by' => $this->user($request)->id,
            'expires_at' => Iso::now()->addDays((int) config('hub.invitation_ttl_days')),
        ]);

        return response()->json([
            'id' => $invitation->id,
            'token' => $token,
            'url' => rtrim((string) config('app.url'), '/').'/invite/'.$token,
            'expiresAt' => Iso::date($invitation->expires_at),
        ], 201);
    }

    public function destroy(Request $request, Invitation $invitation): Response
    {
        $this->admin($request, $invitation->workspace);
        $invitation->delete();

        return response()->noContent();
    }

    /** Public: what the link invites to, so the app can say it before signing in. */
    public function preview(Invitations $invitations, string $token): JsonResponse
    {
        $invitation = $invitations->open($token);

        return response()->json([
            'workspace' => ['id' => $invitation->workspace->id, 'name' => $invitation->workspace->name],
            'email' => $invitation->email,
            'role' => $invitation->role,
            'invitedBy' => $invitation->inviter->name,
            'expiresAt' => Iso::date($invitation->expires_at),
        ]);
    }

    public function accept(Request $request, Invitations $invitations, string $token): JsonResponse
    {
        $user = $this->user($request);
        $invitation = $invitations->open($token);
        if (strcasecmp($invitation->email, $user->email) !== 0) {
            throw new HubException('invitation_invalid', 'This invitation is for another email address', 403);
        }
        $membership = $invitations->accept($invitation, $user);

        return response()->json(Present::workspace($invitation->workspace, $membership->role));
    }
}
