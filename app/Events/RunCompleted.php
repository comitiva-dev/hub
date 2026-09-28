<?php

namespace App\Events;

use App\Models\Run;
use App\Models\UsageRecord;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A run ended (complete, cancelled, failed or expired), with the usage its
 * desktop reported, if any. Part of the hub's public API.
 */
class RunCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Run $run,
        public readonly string $status,
        public readonly ?UsageRecord $usage,
    ) {}
}
