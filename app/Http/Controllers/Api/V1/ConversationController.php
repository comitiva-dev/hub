<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Realtime\Realtime;
use App\Runs\RunLedger;
use App\Support\Iso;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    public function __construct(private readonly Realtime $realtime) {}

    /**
     * Newest activity first, with the caller's unread replies (assistant
     * messages past what they last read, as on the desktop).
     */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->membership($request, $workspace)->user_id;
        $archived = filter_var($request->query('archived', 'false'), FILTER_VALIDATE_BOOL);
        $query = $workspace->conversations()
            ->with('activeRun.user')
            ->where('archived', $archived)
            ->selectSub(fn ($q) => $q->from('messages as m')
                ->selectRaw('count(*)')
                ->whereColumn('m.conversation_id', 'conversations.id')
                ->where('m.role', 'assistant')
                ->whereIn('m.status', ['complete', 'cancelled', 'error'])
                ->whereRaw('m.seq > coalesce((SELECT r.last_read_seq FROM conversation_reads r WHERE r.conversation_id = conversations.id AND r.user_id = ?), 0)', [$user]),
                'unread')
            ->addSelect('conversations.*')
            ->orderByDesc('last_activity_at')->orderByDesc('id');
        if ($request->filled('agentId')) {
            $query->where('agent_id', (string) $request->query('agentId'));
        }

        return response()->json($query->get()->map(
            fn (Conversation $c) => Present::summary($c, (int) $c->getAttribute('unread'))
        )->values());
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->user($request);
        $this->membership($request, $workspace);
        $body = $this->body($request, 'HubConversationDraft');
        if (! $workspace->agents()->whereKey($body->agentId)->exists()) {
            throw HubException::notFound('No such agent in this workspace');
        }
        $conversation = DB::transaction(function () use ($workspace, $body, $user) {
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'agent_id' => $body->agentId,
                'status' => 'idle',
                'archived' => false,
                'last_activity_at' => Iso::now(),
                'created_by' => $user->id,
            ]);
            $conversation->refresh();
            $this->realtime->toWorkspace($workspace, [
                'type' => 'conversation.created',
                'workspaceId' => $workspace->id,
                'conversation' => Present::conversation($conversation),
                'pendingApproval' => null,
                'runner' => null,
            ]);

            return $conversation;
        });

        return response()->json(Present::conversation($conversation), 201);
    }

    /**
     * Renames or archives. A generated title (`titleSource: auto`) replaces
     * only the placeholder: a rename by anyone wins.
     */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->conversationMembership($request, $conversation);
        $body = $this->body($request, 'HubConversationPatch');
        $conversation = DB::transaction(function () use ($conversation, $body) {
            $conversation = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if (property_exists($body, 'title')) {
                $source = $body->titleSource ?? 'user';
                if ($source === 'user' || $conversation->title_source === 'placeholder') {
                    $conversation->title = trim($body->title);
                    $conversation->title_source = $source;
                }
            }
            if (property_exists($body, 'archived')) {
                $conversation->archived = $body->archived;
            }
            $conversation->save();
            $conversation->load('activeRun.user');
            $this->realtime->toWorkspace($conversation->workspace_id, [
                'type' => 'conversation.updated',
                'workspaceId' => $conversation->workspace_id,
                'conversation' => Present::conversation($conversation),
                'pendingApproval' => $conversation->pending_approval,
                'runner' => Present::runner($conversation),
            ]);

            return $conversation;
        });

        return response()->json(Present::conversation($conversation));
    }

    /** Marks every message so far as read by the caller. */
    public function read(Request $request, Conversation $conversation): Response
    {
        $membership = $this->conversationMembership($request, $conversation);
        $seq = (int) $conversation->messages()->max('seq');
        DB::table('conversation_reads')->upsert(
            [['conversation_id' => $conversation->id, 'user_id' => $membership->user_id, 'last_read_seq' => $seq]],
            ['conversation_id', 'user_id'],
            ['last_read_seq'],
        );

        return response()->noContent();
    }

    /** Asks the desktop running this conversation to stop (a no-op when nothing runs). */
    public function cancel(Request $request, Conversation $conversation, RunLedger $ledger): Response
    {
        $ledger->requestCancel($conversation, $this->conversationMembership($request, $conversation));

        return response()->noContent();
    }
}
