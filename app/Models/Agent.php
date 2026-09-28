<?php

namespace App\Models;

use App\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/** A shared agent: a provider and model, never a connection (connections are local).
 *
 * @property \stdClass $avatar
 * @property \stdClass|null $params
 * @property list<string>|null $tags
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['workspace_id', 'name', 'avatar', 'provider', 'model', 'role', 'params', 'permission_policy', 'tags', 'created_by'])]
class Agent extends Model
{
    use HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['avatar' => Json::class, 'params' => Json::class, 'tags' => Json::class];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<ToolServer, $this> */
    public function toolServers(): BelongsToMany
    {
        return $this->belongsToMany(ToolServer::class, 'agent_tool_servers')->withPivot('position')->orderByPivot('position');
    }
}
