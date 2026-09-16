<?php

declare(strict_types=1);

use App\Console\Commands\CollectDueInvoicesCommand;
use App\Console\Commands\ConsumeRiskScoresCommand;
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

/*
| The dunning sweep runs an hour after renewals, not alongside them. A renewal
| issues an invoice whose first attempt is due immediately, and the listener
| that issues it queues its own collection job — so by 04:00 today's invoices
| have already been tried once and this sweep is picking up the retries, which
| is what it is for. Running them together would double-queue every new
| invoice and rely on the job's uniqueness to sort it out.
*/
Schedule::command(CollectDueInvoicesCommand::class)
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| The return leg from the Python risk worker. Every five minutes rather than
| daily: a risk score is only useful while the dunning cycle it describes is
| still running, and the drain is one indexed pop per message.
|
| No withoutOverlapping() lock is needed for correctness — LPOP is atomic, so
| two concurrent drains split the list rather than double-applying it — but it
| is set anyway to keep the process count predictable.
*/
Schedule::command(ConsumeRiskScoresCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
