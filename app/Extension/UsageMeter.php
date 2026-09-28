<?php

namespace App\Extension;

use App\Models\UsageRecord;

/**
 * Receives every usage record once it is stored. Usage reported by desktops
 * is shown, not billed (ADR 0015); a meter may still count it.
 */
interface UsageMeter
{
    public function record(UsageRecord $record): void;
}
