<?php

namespace App\Models;

use App\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['conversation_id', 'run_id', 'tool_use_id', 'tool_server_id', 'tool_name', 'input', 'decision', 'decided_by', 'decided_at'])]
class ToolApproval extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['input' => Json::class, 'decided_at' => 'datetime'];
    }
}
