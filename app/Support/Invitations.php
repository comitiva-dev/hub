<?php

namespace App\Support;

use App\Events\MemberAdded;
use App\Exceptions\HubException;
use App\Extension\PlanLimits;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use App\Realtime\Realtime;
use Illuminate\Support\Facades\DB;

class Invitations
{
    public function __construct(
        private readonly Realtime $realtime,
        private readonly PlanLimits $limits,
    ) {}

    /** An invitation that can still be accepted, found by the token in its link. */
    public function open(string $token): Invitation
    {
        $invitation = Invitation::where('token_hash', Invitation::hashToken($token))->first();
        if ($invitation === null || ! $invitation->isOpen()) {
            throw new HubException('invitation_invalid', 'This invitation is invalid or expired', 404);
        }

        return $invitation;
    }

    /** Makes the user a member (idempotent for someone who already is). */
    public function accept(Invitation $invitation, User $user): Membership
    {
        return DB::transaction(function () use ($invitation, $user) {
            $workspace = $invitation->workspace()->lockForUpdate()->firstOrFail();
            $existing = $workspace->membershipOf($user);
            $invitation->update(['accepted_at' => Iso::now(), 'accepted_by' => $user->id]);
            if ($existing !== null) {
                return $existing;
            }
            $max = $this->limits->maxMembers($workspace);
            if ($max !== null && $workspace->memberships()->count() >= $max) {
                throw HubException::forbidden('This workspace has no room for more members');
            }
            $membership = Membership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);
            $membership->setRelation('user', $user);
            $this->realtime->toWorkspace($workspace, [
                'type' => 'member.added',
                'workspaceId' => $workspace->id,
                'member' => Present::member($membership),
            ]);
            $this->realtime->toUser($user->id, [
                'type' => 'workspace.joined',
                'workspace' => Present::workspace($workspace, $membership->role),
            ]);
            DB::afterCommit(fn () => MemberAdded::dispatch($membership));

            return $membership;
        });
    }
}
