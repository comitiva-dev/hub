<?php

namespace App\Extension;

use App\Models\User;
use App\Models\Workspace;

/** Where security-relevant actions are recorded. The community edition keeps none. */
interface AuditSink
{
    /** @param  array<string, mixed>  $data */
    public function record(string $action, ?User $actor, ?Workspace $workspace, array $data = []): void;
}
