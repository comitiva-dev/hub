<?php

namespace App\Extension\Community;

use App\Extension\BillingGateway;
use App\Models\Workspace;

class NoBilling implements BillingGateway
{
    public function enabled(): bool
    {
        return false;
    }

    public function portalUrl(Workspace $workspace): ?string
    {
        return null;
    }
}
