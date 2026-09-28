<?php

use Illuminate\Support\Facades\Schedule;

// Runs whose desktop stopped renewing their lease end as interrupted (ADR 0017).
Schedule::command('hub:expire-runs')->everyFifteenSeconds()->withoutOverlapping();
