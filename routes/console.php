<?php

declare(strict_types=1);

use App\Console\Commands\RenewDueSubscriptionsCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The renewal sweep runs at 03:00 UTC — outside Brazilian business hours, so
| a slow run never competes with customer traffic.
|
| withoutOverlapping() guards against a sweep that outlives its window and
| meets the next one. onOneServer() guards against every app node in the
| cluster running the same sweep simultaneously; it needs a shared lock store,
| which is why the scheduler requires Redis rather than the file cache.
|
| Both are belt and braces: the jobs themselves are unique-while-queued and
| idempotent when they run. Three independent guards, because a double-billed
| customer is not a bug you get to fix quietly.
|
*/

Schedule::command(RenewDueSubscriptionsCommand::class)
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
