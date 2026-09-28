<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Usage a desktop reported for a run it executed; its cost was frozen there.
 *
 * @property Carbon $created_at
 */
#[Fillable(['workspace_id', 'user_id', 'agent_id', 'conversation_id', 'message_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'estimated', 'cost_usd', 'cost_source', 'cost_estimated', 'latency_ms', 'created_at'])]
class UsageRecord extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'estimated' => 'boolean',
            'cost_usd' => 'float',
            'cost_estimated' => 'boolean',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
