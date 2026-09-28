<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Extension\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Invitations;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Desktops sign in with email and password and get a personal access token
 * for that device (kept in the desktop's SecretStore, ADR 0017).
 */
class AuthController extends Controller
{
    public function __construct(private readonly IdentityProvider $identity) {}

    public function register(Request $request, Invitations $invitations): JsonResponse
    {
        $body = $this->body($request, 'HubRegisterInput');
        $invitation = null;
        if (isset($body->invitationToken)) {
            $invitation = $invitations->open($body->invitationToken);
            if (strcasecmp($invitation->email, $body->email) !== 0) {
                throw new HubException('invitation_invalid', 'This invitation is for another email address', 403);
            }
        } elseif (config('hub.registration') === 'invite-only' && User::query()->exists()) {
            throw HubException::forbidden('This hub creates accounts by invitation only');
        }
        if (User::whereRaw('lower(email) = lower(?)', [$body->email])->exists()) {
            throw new HubException('email_taken', 'An account with this email exists', 409);
        }

        [$user, $token] = DB::transaction(function () use ($body, $invitation, $invitations) {
            $user = $this->identity->register($body->name, $body->email, $body->password);
            if ($invitation instanceof Invitation) {
                $invitations->accept($invitation, $user);
            }

            return [$user, $user->createToken($body->deviceName)->plainTextToken];
        });

        return response()->json(['token' => $token, 'user' => Present::user($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $body = $this->body($request, 'HubLoginInput');
        $user = $this->identity->attempt($body->email, $body->password);
        if ($user === null) {
            throw new HubException('invalid_credentials', 'Wrong email or password', 401);
        }

        return response()->json([
            'token' => $user->createToken($body->deviceName)->plainTextToken,
            'user' => Present::user($user),
        ]);
    }

    /** Revokes the token this request used (a session is ended by /logout instead). */
    public function logout(Request $request): Response
    {
        $token = $this->user($request)->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(Present::user($this->user($request)));
    }
}
