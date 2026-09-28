<?php

namespace App\Support;

use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A workspace's usage, with the desktop's arithmetic (its UsageRepository):
 * API costs and CLI equivalents summed apart, closed-open windows, days on
 * the viewer's calendar. Grouped by member where the desktop groups by
 * connection: connections are local.
 */
class UsageReports
{
    private const CLI = "('claude-code','codex','gemini-cli')";

    private const AGGREGATE = '
        count(*) AS runs,
        coalesce(sum(u.input_tokens), 0) AS input_tokens,
        coalesce(sum(u.output_tokens), 0) AS output_tokens,
        coalesce(sum(u.cache_read_tokens), 0) AS cache_read_tokens,
        coalesce(sum(u.cache_write_tokens), 0) AS cache_write_tokens,
        coalesce(sum(CASE WHEN u.provider IN '.self::CLI.' THEN 0 ELSE u.cost_usd END), 0) AS cost_usd,
        coalesce(sum(CASE WHEN u.provider IN '.self::CLI.' THEN u.cost_usd ELSE 0 END), 0) AS cost_usd_cli,
        coalesce(bool_or(u.estimated), false) AS any_estimated,
        coalesce(bool_or(u.cost_usd IS NULL), false) AS any_unpriced
    ';

    private const WINDOW = 'u.workspace_id = ? AND u.created_at >= ? AND u.created_at < ?';

    /** @return array<string, mixed> UsageSummary */
    public function summary(Workspace $workspace, Carbon $from, Carbon $to): array
    {
        $window = [$workspace->id, $from, $to];
        $total = DB::selectOne('SELECT '.self::AGGREGATE.' FROM usage_records u WHERE '.self::WINDOW, $window);

        $byMember = DB::select(
            'SELECT u.user_id AS key, coalesce(us.name, \'\') AS label, NULL AS provider,
                    (m.id IS NULL) AS deleted, '.self::AGGREGATE.'
               FROM usage_records u
               LEFT JOIN users us ON us.id = u.user_id
               LEFT JOIN memberships m ON m.user_id = u.user_id AND m.workspace_id = u.workspace_id
              WHERE '.self::WINDOW.'
              GROUP BY u.user_id, us.name, m.id ORDER BY runs DESC, label ASC',
            $window,
        );
        $byAgent = DB::select(
            'SELECT u.agent_id AS key, coalesce(a.name, \'\') AS label, NULL AS provider,
                    (a.id IS NULL) AS deleted, '.self::AGGREGATE.'
               FROM usage_records u LEFT JOIN agents a ON a.id = u.agent_id
              WHERE '.self::WINDOW.'
              GROUP BY u.agent_id, a.name, a.id ORDER BY runs DESC, label ASC',
            $window,
        );
        $byModel = DB::select(
            'SELECT u.provider || \'/\' || u.model AS key, u.model AS label, u.provider AS provider,
                    false AS deleted, '.self::AGGREGATE.'
               FROM usage_records u WHERE '.self::WINDOW.'
              GROUP BY u.provider, u.model ORDER BY runs DESC, label ASC',
            $window,
        );

        return [
            'totals' => self::totals($total),
            'byConnection' => array_map(self::row(...), $byMember),
            'byAgent' => array_map(self::row(...), $byAgent),
            'byModel' => array_map(self::row(...), $byModel),
        ];
    }

    /**
     * One point per day on the viewer's calendar, days without runs included.
     *
     * @return list<array<string, mixed>> UsageBucket[]
     */
    public function timeseries(Workspace $workspace, Carbon $from, Carbon $to, int $tzOffsetMinutes): array
    {
        $rows = DB::select(
            "SELECT to_char((u.created_at AT TIME ZONE 'UTC') + make_interval(mins => ?), 'YYYY-MM-DD') AS day, "
                .self::AGGREGATE.' FROM usage_records u WHERE '.self::WINDOW.' GROUP BY day ORDER BY day',
            [$tzOffsetMinutes, $workspace->id, $from, $to],
        );
        $found = [];
        foreach ($rows as $row) {
            $found[$row->day] = ['day' => $row->day, ...self::totals($row)];
        }
        $out = [];
        foreach (self::days($from, $to, $tzOffsetMinutes) as $day) {
            $out[] = $found[$day] ?? ['day' => $day, ...self::totals(null)];
        }

        return $out;
    }

    /** @return array<string, mixed> UsageTotals */
    public function conversation(string $conversationId): array
    {
        return self::totals(DB::selectOne('SELECT '.self::AGGREGATE.' FROM usage_records u WHERE u.conversation_id = ?', [$conversationId]));
    }

    /**
     * Days a closed-open window covers once shifted to the viewer's offset
     * (the desktop's `daysIn`).
     *
     * @return list<string>
     */
    public static function days(Carbon $from, Carbon $to, int $tzOffsetMinutes): array
    {
        $first = $from->copy()->utc()->addMinutes($tzOffsetMinutes);
        $last = $to->copy()->utc()->addMinutes($tzOffsetMinutes);
        $days = [];
        for ($cursor = $first->copy()->startOfDay(); $cursor->lessThan($last) && count($days) < 3660; $cursor->addDay()) {
            $days[] = $cursor->format('Y-m-d');
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private static function totals(?object $row): array
    {
        if ($row === null || (int) $row->runs === 0) {
            return [
                'runs' => 0, 'inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0,
                'costUsd' => 0.0, 'costUsdCli' => 0.0, 'anyEstimated' => false, 'anyUnpriced' => false,
            ];
        }

        return [
            'runs' => (int) $row->runs,
            'inputTokens' => (int) $row->input_tokens,
            'outputTokens' => (int) $row->output_tokens,
            'cacheReadTokens' => (int) $row->cache_read_tokens,
            'cacheWriteTokens' => (int) $row->cache_write_tokens,
            'costUsd' => (float) $row->cost_usd,
            'costUsdCli' => (float) $row->cost_usd_cli,
            'anyEstimated' => (bool) $row->any_estimated,
            'anyUnpriced' => (bool) $row->any_unpriced,
        ];
    }

    /** @return array<string, mixed> UsageSummaryRow */
    private static function row(object $row): array
    {
        return [
            'key' => (string) $row->key,
            'label' => (string) $row->label,
            'provider' => $row->provider,
            'deleted' => (bool) $row->deleted,
            ...self::totals($row),
        ];
    }
}
