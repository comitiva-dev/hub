<?php

namespace App\Models;

use App\Casts\Json;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property list<\stdClass> $content blocks, in the contract's Block shape
 * @property \stdClass|null $error
 * @property Carbon $created_at
 */
#[Fillable(['conversation_id', 'seq', 'role', 'content', 'status', 'error', 'author_id', 'search_text'])]
class Message extends Model
{
    use HasUlids;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['content' => Json::class, 'error' => Json::class, 'seq' => 'integer'];
    }

    /** Who wrote it (a user message), or whose desktop ran it (a reply). */
    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
