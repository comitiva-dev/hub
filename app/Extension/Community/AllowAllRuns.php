<?php

namespace App\Extension\Community;

use App\Extension\RunGate;
use App\Models\Conversation;
use App\Models\User;

class AllowAllRuns implements RunGate
{
    public function refuse(User $user, Conversation $conversation): ?string
    {
        return null;
    }
}
