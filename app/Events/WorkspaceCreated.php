<?php

namespace App\Events;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/** Part of the hub's public API (docs/extension-points.md). */
class WorkspaceCreated
{
    use Dispatchable;

    public function __construct(public readonly Workspace $workspace, public readonly User $owner) {}
}
