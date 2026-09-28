<?php

namespace App\Extension;

use App\Models\User;
use App\Models\Workspace;

/** How much a workspace or user may have; null is unlimited. */
interface PlanLimits
{
    public function maxMembers(Workspace $workspace): ?int;

    public function maxWorkspaces(User $user): ?int;
}
