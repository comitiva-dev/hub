<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Run;
use App\Runs\RunLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The executing desktop's side of a turn (ADR 0017 in comitiva): start,
 * event batches, approvals, heartbeats, finish.
 */
class RunController extends Controller
{
    public function __construct(private readonly RunLedger $ledger) {}

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->conversationMembership($request, $conversation);
        $body = $this->body($request, 'HubRunStartInput');
        $user = $this->user($request);

        return response()->json(
            $this->ledger->start($conversation, $user, $user->currentTokenId(), $body->runId, $body->content ?? null),
            201,
        );
    }

    public function events(Request $request, Run $run): JsonResponse
    {
        $body = $this->body($request, 'HubRunEventsInput');
        $user = $this->user($request);

        return response()->json(['rev' => $this->ledger->apply($run, $user, $user->currentTokenId(), $body->batch, $body->events)]);
    }

    public function approval(Request $request, Run $run): Response
    {
        $body = $this->body($request, 'HubRunApprovalInput');
        $user = $this->user($request);
        $this->ledger->approve($run, $user, $user->currentTokenId(), $body->toolUseId, $body->decision);

        return response()->noContent();
    }

    public function heartbeat(Request $request, Run $run): Response
    {
        $user = $this->user($request);
        $this->ledger->heartbeat($run, $user, $user->currentTokenId());

        return response()->noContent();
    }

    public function finish(Request $request, Run $run): JsonResponse
    {
        $body = $this->body($request, 'HubRunFinishInput');
        $user = $this->user($request);

        return response()->json($this->ledger->finish($run, $user, $user->currentTokenId(), $body));
    }
}
