<?php

namespace App\Http\Controllers;

use App\Contract\Contract;
use App\Exceptions\HubException;
use App\Models\Conversation;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use stdClass;

abstract class Controller
{
    /**
     * The request body, validated against a contract schema. Objects stay
     * stdClass, so `{}` and `[]` keep their meaning.
     */
    protected function body(Request $request, string $schema): stdClass
    {
        $raw = $request->getContent();
        try {
            $body = json_decode($raw === '' ? '{}' : $raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HubException::invalid('The body is not JSON');
        }
        $errors = app(Contract::class)->errors($schema, $body);
        if ($errors !== null) {
            throw HubException::invalid($errors);
        }
        if (! $body instanceof stdClass) {
            throw HubException::invalid('The body must be an object');
        }

        return $body;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new HubException('hub_auth_required', 'Sign in first', 401);
        }

        return $user;
    }

    /**
     * The caller's membership. Someone outside the workspace gets a 404, so
     * workspace ids do not leak.
     */
    protected function membership(Request $request, Workspace $workspace): Membership
    {
        $membership = $workspace->membershipOf($this->user($request));
        if ($membership === null) {
            throw HubException::notFound('No such workspace');
        }

        return $membership;
    }

    protected function admin(Request $request, Workspace $workspace): Membership
    {
        $membership = $this->membership($request, $workspace);
        if (! $membership->isAdmin()) {
            throw HubException::forbidden('Only owners and admins can do that');
        }

        return $membership;
    }

    protected function owner(Request $request, Workspace $workspace): Membership
    {
        $membership = $this->membership($request, $workspace);
        if (! $membership->isOwner()) {
            throw HubException::forbidden('Only owners can do that');
        }

        return $membership;
    }

    protected function conversationMembership(Request $request, Conversation $conversation): Membership
    {
        $membership = $conversation->workspace->membershipOf($this->user($request));
        if ($membership === null) {
            throw HubException::notFound('No such conversation');
        }

        return $membership;
    }
}
