<?php

namespace App\Events;

use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/** Part of the hub's public API (docs/extension-points.md). */
class MemberRemoved
{
    use Dispatchable;

    public function __construct(public readonly Workspace $workspace, public readonly string $userId) {}
}
