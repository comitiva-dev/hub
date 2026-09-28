<?php

namespace App\Http\Controllers\Api\V1;

use App\Contract\Contract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    /** Bumped only with changes that break existing desktops (ADR 0016). */
    public const API_VERSION = 1;

    /** HubMeta: what this hub is and where its WebSocket is. Public. */
    public function show(Contract $contract): JsonResponse
    {
        $reverb = config('broadcasting.default') === 'reverb';

        return response()->json([
            'apiVersion' => self::API_VERSION,
            'edition' => config('hub.edition'),
            'contractVersion' => $contract->version(),
            'capabilities' => [
                'execution' => false,
                'registration' => config('hub.registration') === 'invite-only' ? 'invite-only' : 'open',
            ],
            'realtime' => $reverb ? [
                'key' => (string) config('broadcasting.connections.reverb.key'),
                'host' => (string) config('hub.realtime.host'),
                'port' => (int) config('hub.realtime.port'),
                'scheme' => config('hub.realtime.scheme') === 'https' ? 'https' : 'http',
            ] : null,
        ]);
    }
}
