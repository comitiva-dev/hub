<?php

namespace App\Extension\Community;

use App\Extension\UsageMeter;
use App\Models\UsageRecord;

/** The record is already stored; nothing else counts it. */
class StoredUsageMeter implements UsageMeter
{
    public function record(UsageRecord $record): void {}
}
