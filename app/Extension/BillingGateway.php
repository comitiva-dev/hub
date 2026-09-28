<?php

namespace App\Extension;

use App\Models\Workspace;

/**
 * Billing for a workspace. The community edition bills nothing; the hosted
 * hub's enterprise package binds its own (ADR 0015 in comitiva).
 */
interface BillingGateway
{
    public function enabled(): bool;

    /** Where a workspace's admins manage billing; null when there is none. */
    public function portalUrl(Workspace $workspace): ?string;
}
