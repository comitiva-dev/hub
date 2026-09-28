<?php

namespace App\Extension;

use App\Models\Conversation;
use App\Models\User;

/**
 * Consulted before a run starts: usage policies and limits. Returns null to
 * allow, or the reason it refuses (sent as `forbidden`).
 */
interface RunGate
{
    public function refuse(User $user, Conversation $conversation): ?string;
}
