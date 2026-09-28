<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The quick switcher's search in a workspace, with the desktop's rules:
 * titles by substring, messages by full text with every word required and
 * the last one as a prefix, diacritics ignored, archived conversations left
 * out (SearchResult).
 */
class SearchController extends Controller
{
    private const OPEN = "\u{2}";

    private const CLOSE = "\u{3}";

    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $query = trim((string) $request->query('query', ''));
        $limit = (int) $request->query('limit', '20');
        if ($query === '' || mb_strlen($query) > 200 || $limit < 1 || $limit > 100) {
            throw HubException::invalid('query must have 1–200 characters and limit be 1–100');
        }

        return response()->json([
            'conversations' => $this->conversations($workspace, $query, $limit),
            'messages' => $this->messages($workspace, $query, $limit),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function conversations(Workspace $workspace, string $query, int $limit): array
    {
        $pattern = '%'.addcslashes($query, '\\%_').'%';
        $rows = DB::select(
            "SELECT id, agent_id, title, last_activity_at FROM conversations
              WHERE workspace_id = ? AND archived = false
                AND hub_unaccent(lower(title)) LIKE hub_unaccent(lower(?)) ESCAPE '\\'
              ORDER BY last_activity_at DESC LIMIT ?",
            [$workspace->id, $pattern, $limit],
        );

        return array_map(fn ($r) => [
            'conversationId' => $r->id,
            'agentId' => $r->agent_id,
            'title' => $r->title,
            'lastActivityAt' => Iso::date(Carbon::parse($r->last_activity_at)),
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function messages(Workspace $workspace, string $query, int $limit): array
    {
        $tsquery = self::tsquery($query);
        if ($tsquery === null) {
            return [];
        }
        $options = 'StartSel='.self::OPEN.', StopSel='.self::CLOSE.', MaxWords=16, MinWords=6, MaxFragments=1, FragmentDelimiter=…';
        $rows = DB::select(
            "SELECT m.id, m.conversation_id, c.agent_id, c.title, m.seq, m.role, m.created_at,
                    ts_headline('hub_search', m.search_text, q, ?) AS snippet
               FROM messages m
               JOIN conversations c ON c.id = m.conversation_id,
                    to_tsquery('hub_search', ?) q
              WHERE c.workspace_id = ? AND c.archived = false AND m.status <> 'streaming'
                AND to_tsvector('hub_search'::regconfig, coalesce(m.search_text, '')) @@ q
              ORDER BY ts_rank(to_tsvector('hub_search'::regconfig, coalesce(m.search_text, '')), q) DESC,
                       m.created_at DESC
              LIMIT ?",
            [$options, $tsquery, $workspace->id, $limit],
        );

        return array_map(fn ($r) => [
            'conversationId' => $r->conversation_id,
            'agentId' => $r->agent_id,
            'messageId' => $r->id,
            'seq' => (int) $r->seq,
            'role' => $r->role,
            'createdAt' => Iso::date(Carbon::parse($r->created_at)),
            'conversationTitle' => $r->title,
            'snippet' => self::snippet((string) $r->snippet),
        ], $rows);
    }

    /**
     * What the user typed as a tsquery: letter and digit runs only (so no
     * operator can be typed), all required, the last one as a prefix.
     */
    public static function tsquery(string $input): ?string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $input, $matches);
        $words = $matches[0];
        if ($words === []) {
            return null;
        }
        $last = array_pop($words);

        return implode(' & ', [...$words, $last.':*']);
    }

    /**
     * ts_headline output as pieces split at the match markers (SearchSnippet).
     *
     * @return list<array{text: string, match: bool}>
     */
    public static function snippet(string $raw): array
    {
        $out = [];
        $match = false;
        foreach (preg_split('/(['.self::OPEN.self::CLOSE.'])/u', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if ($piece === self::OPEN) {
                $match = true;
            } elseif ($piece === self::CLOSE) {
                $match = false;
            } elseif ($piece !== '') {
                $text = (string) preg_replace('/\s+/u', ' ', $piece);
                $lastKey = array_key_last($out);
                if ($lastKey !== null && $out[$lastKey]['match'] === $match) {
                    $out[$lastKey]['text'] .= $text;
                } else {
                    $out[] = ['text' => $text, 'match' => $match];
                }
            }
        }

        return $out;
    }
}
