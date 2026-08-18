<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\CarbonImmutable;

/**
 * The current time, as a dependency.
 *
 * Reading the clock is I/O. Treating it as such means a test can say "it is
 * the 16th of June" by swapping an implementation instead of freezing global
 * state, and it makes it obvious at a glance which services are time-sensitive
 * — they are the ones that ask for a Clock in their constructor.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
