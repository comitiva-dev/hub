<?php

namespace App\Extension\Community;

use App\Extension\AuditSink;
use App\Models\User;
use App\Models\Workspace;

class NoAudit implements AuditSink
{
    public function record(string $action, ?User $actor, ?Workspace $workspace, array $data = []): void {}
}
