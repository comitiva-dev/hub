<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A turn a desktop is running, identified by the desktop's own run id.
 *
 * @property string $status active | finished | expired
 * @property Carbon $lease_expires_at
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['id', 'conversation_id', 'reply_message_id', 'user_id', 'token_id', 'status', 'last_batch', 'lease_expires_at', 'started_at', 'finished_at'])]
class Run extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.vP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_batch' => 'integer',
            'token_id' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaseExpired(): bool
    {
        return $this->lease_expires_at->isPast();
    }
}
