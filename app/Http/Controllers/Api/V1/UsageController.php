<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\UsageRecord;
use App\Models\Workspace;
use App\Support\Present;
use App\Support\UsageReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Usage the members' desktops reported, visible to every member. Windows
 * come as `from`, `to` (ISO 8601, closed-open) and `tzOffsetMinutes`.
 */
class UsageController extends Controller
{
    public function __construct(private readonly UsageReports $reports) {}

    public function summary(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        [$from, $to] = $this->window($request);

        return response()->json($this->reports->summary($workspace, $from, $to));
    }

    public function timeseries(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        [$from, $to, $offset] = $this->window($request);

        return response()->json($this->reports->timeseries($workspace, $from, $to, $offset));
    }

    /** HubUsageRecord[] in the window, oldest first (the CSV export). */
    public function records(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        [$from, $to] = $this->window($request);
        $records = UsageRecord::where('workspace_id', $workspace->id)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderBy('created_at')->orderBy('id')->limit(100_000)->get();

        return response()->json($records->map(fn (UsageRecord $r) => Present::usageRecord($r))->values());
    }

    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->conversationMembership($request, $conversation);

        return response()->json($this->reports->conversation($conversation->id));
    }

    /** @return array{Carbon, Carbon, int} */
    private function window(Request $request): array
    {
        try {
            $from = Carbon::parse((string) $request->query('from'))->utc();
            $to = Carbon::parse((string) $request->query('to'))->utc();
        } catch (\Throwable) {
            throw HubException::invalid('from and to must be ISO 8601 dates');
        }
        $offset = (int) $request->query('tzOffsetMinutes', '0');
        if (! $request->filled('from') || ! $request->filled('to') || $offset < -840 || $offset > 840) {
            throw HubException::invalid('from, to and tzOffsetMinutes (±840) are required');
        }

        return [$from, $to, $offset];
    }
}
