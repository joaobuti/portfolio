<?php

declare(strict_types=1);

use App\Console\Commands\CollectDueInvoicesCommand;
use App\Console\Commands\ConsumeRiskScoresCommand;
use App\Console\Commands\ExpireAdjustmentInvoicesCommand;
use App\Console\Commands\RenewDueSubscriptionsCommand;
use App\Console\Commands\RepriceDegradedInvoicesCommand;
use App\Console\Commands\VerifyPendingCheckoutsCommand;
use Illuminate\Support\Facades\Schedule;

if (config('demo.enabled') === true) {
    Schedule::command(App\Console\Commands\PruneDemoWorkspacesCommand::class)
        ->everyFiveMinutes()->withoutOverlapping()->onOneServer();
}

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Hourly renewal catches billing-day boundaries and resumes a period after
| an unpaid upgrade has been reverted. Persisted dates and unique jobs guard
| retries. Billing-day boundaries use America/Sao_Paulo in BillingCalendar.
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
    ->hourly()
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
| Collection runs every minute so initial immediate invoices, adjustment
| invoices and recovered pricing reach the existing collector promptly.
| Persisted next_attempt_at remains authoritative for retry timing.
*/
Schedule::command(CollectDueInvoicesCommand::class)
    ->everyMinute()
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

Schedule::command(VerifyPendingCheckoutsCommand::class)
    ->everyMinute()->withoutOverlapping()->onOneServer()->runInBackground();

Schedule::command(RepriceDegradedInvoicesCommand::class)
    ->everyMinute()->withoutOverlapping()->onOneServer()->runInBackground();

Schedule::command(ExpireAdjustmentInvoicesCommand::class)
    ->everyMinute()->withoutOverlapping()->onOneServer()->runInBackground();
