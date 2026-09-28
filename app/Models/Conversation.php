<?php

namespace App\Models;

use App\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $rev event revision: +1 with every message event (ADR 0008)
 * @property \stdClass|null $pending_approval the tool call waiting for its desktop's answer
 * @property Carbon $last_activity_at
 * @property Carbon $created_at
 */
#[Fillable(['workspace_id', 'agent_id', 'title', 'title_source', 'status', 'archived', 'last_activity_at', 'rev', 'pending_approval', 'created_by'])]
class Conversation extends Model
{
    use HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'last_activity_at' => 'datetime',
            'rev' => 'integer',
            'pending_approval' => Json::class,
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Run, $this> */
    public function activeRun(): HasOne
    {
        return $this->hasOne(Run::class)->where('status', 'active');
    }

    /** Takes the next event revision; the caller holds the row lock. */
    public function nextRev(): int
    {
        $this->rev += 1;

        return $this->rev;
    }
}
