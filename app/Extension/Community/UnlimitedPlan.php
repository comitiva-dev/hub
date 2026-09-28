<?php

namespace App\Extension\Community;

use App\Extension\PlanLimits;
use App\Models\User;
use App\Models\Workspace;

class UnlimitedPlan implements PlanLimits
{
    public function maxMembers(Workspace $workspace): ?int
    {
        return null;
    }

    public function maxWorkspaces(User $user): ?int
    {
        return null;
    }
}
