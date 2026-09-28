<?php

namespace App\Http\Controllers;

use App\Exceptions\HubException;
use App\Extension\IdentityProvider;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/** Email and password sessions (Sanctum's stateful SPA mode) for the web UI. */
class WebSessionController extends Controller
{
    public function login(Request $request, IdentityProvider $identity): JsonResponse
    {
        $body = $this->body($request, 'HubLoginInput');
        $user = $identity->attempt($body->email, $body->password);
        if ($user === null) {
            throw new HubException('invalid_credentials', 'Wrong email or password', 401);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(Present::user($user));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
