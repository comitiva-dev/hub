<?php

namespace App\Models;

use App\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A workspace MCP server: http only; secret header values stay on each desktop.
 *
 * @property \stdClass|null $headers
 * @property Carbon $created_at
 */
#[Fillable(['workspace_id', 'name', 'url', 'headers', 'enabled', 'created_by'])]
class ToolServer extends Model
{
    use HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['headers' => Json::class, 'enabled' => 'boolean'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
