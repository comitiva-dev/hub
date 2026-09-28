<?php

namespace App\Events;

use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/** Part of the hub's public API (docs/extension-points.md). */
class MemberAdded
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership) {}
}
