<?php

declare(strict_types=1);

use App\Support\Clock;
use App\Support\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\CarbonImmutable;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // The plan catalogue is cached, and the cache outlives a single test in
    // the array store. Without this, one test's plans leak into the next.
    ->beforeEach(fn () => Cache::flush())
    ->in('Feature');

/**
 * Pin the application clock for a test.
 *
 * Billing is time-dependent everywhere, so almost every feature test needs to
 * say "pretend it is the 16th". Because time enters the application through a
 * single binding, this helper is the whole mechanism — no global state to
 * freeze and no travelling back afterwards.
 */
function freezeClockAt(string $timestamp): FrozenClock
{
    $clock = FrozenClock::at($timestamp);

    app()->instance(Clock::class, $clock);

    // The Clock covers everything the services do. Carbon's test-now covers the
    // places the framework itself reads the wall clock — factories, model
    // timestamps, `isFuture()` on a cast attribute.
    Carbon::setTestNow(Carbon::parse($timestamp));
    CarbonImmutable::setTestNow(CarbonImmutable::parse($timestamp));

    return $clock;
}
