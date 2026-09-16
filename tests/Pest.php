<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use App\Support\Clock;
use App\Support\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // The plan catalogue is cached, and the cache outlives a single test in
    // the array store. Without this, one test's plans leak into the next.
    ->beforeEach(fn () => Cache::flush())
    ->in('Feature');

/**
 * Authenticate as a staff user — one that may act on any customer.
 *
 * The default `['*']` grants every ability, because most tests are about
 * something other than scoping. Tests that *are* about scoping pass an explicit
 * list, and that asymmetry is intentional: it keeps the ability assertions in
 * the handful of tests that mean them rather than spread across all of them.
 *
 * @param  list<string>  $abilities
 */
function actingAsStaff(array $abilities = ['*']): User
{
    $user = User::factory()->staff()->create();

    Sanctum::actingAs($user, $abilities);

    return $user;
}

/**
 * Authenticate as a user bound to one customer — the self-service case.
 *
 * @param  list<string>  $abilities
 */
function actingAsCustomerUser(Customer $customer, array $abilities = ['*']): User
{
    $user = User::factory()->belongingTo($customer)->create();

    Sanctum::actingAs($user, $abilities);

    return $user;
}

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
