<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Dates as the contract writes them: ISO 8601 UTC with milliseconds. */
final class Iso
{
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function now(): Carbon
    {
        return Carbon::now()->utc();
    }
}
