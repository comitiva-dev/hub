<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * MessagePage: the latest page (or the one before `beforeSeq`), oldest
     * first, at the conversation's `rev`. The conversation row is share-locked
     * while the page is read, so no event lands between the two (ADR 0008).
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->conversationMembership($request, $conversation);
        $limit = (int) $request->query('limit', '100');
        $before = $request->query('beforeSeq');
        if ($limit < 1 || $limit > 500 || ($before !== null && ! ctype_digit((string) $before))) {
            throw HubException::invalid('limit must be 1–500 and beforeSeq a sequence number');
        }

        $page = DB::transaction(function () use ($conversation, $limit, $before) {
            $rev = (int) Conversation::whereKey($conversation->id)->sharedLock()->value('rev');
            $query = $conversation->messages()->orderByDesc('seq')->limit($limit + 1);
            if ($before !== null) {
                $query->where('seq', '<', (int) $before);
            }
            $rows = $query->get();

            return ['rows' => $rows, 'rev' => $rev];
        });
        $rows = $page['rows'];
        $hasMore = $rows->count() > $limit;

        return response()->json([
            'messages' => $rows->take($limit)->reverse()->map(fn (Message $m) => Present::message($m))->values(),
            'hasMore' => $hasMore,
            'rev' => $page['rev'],
        ]);
    }
}
