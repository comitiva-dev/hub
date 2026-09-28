<?php

namespace App\Console\Commands;

use App\Runs\RunLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:expire-runs')]
#[Description('End runs whose desktop stopped renewing its lease (replies become interrupted)')]
class ExpireRuns extends Command
{
    public function handle(RunLedger $ledger): int
    {
        $count = $ledger->expireStale();
        if ($count > 0) {
            $this->info("Expired {$count} run(s).");
        }

        return self::SUCCESS;
    }
}
