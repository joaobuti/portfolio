<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Plan;
use App\Observers\PlanObserver;
use App\Support\Clock;
use App\Support\SystemClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place the application learns what time it is. Swap this
        // binding in a test and every time-dependent behaviour follows.
        $this->app->singleton(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureRateLimiting();

        Plan::observe(PlanObserver::class);
    }

    /**
     * Turn Eloquent's quiet failure modes into loud ones.
     *
     * `shouldBeStrict()` makes lazy loading, silently discarded attributes and
     * access to missing attributes throw. Every one of those is a bug that
     * would otherwise ship as an N+1 query or a field that mysteriously never
     * saves. Strict in development and CI, permissive in production — a
     * customer should never see a 500 for a mistake that a test should have
     * caught.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // `migrate:fresh` and `db:wipe` should not be one typo away in prod.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Immutable dates everywhere.
     *
     * Mutable Carbon instances are a shared-mutable-state bug waiting to
     * happen: `$subscription->current_period_end->addMonth()` silently rewrites
     * the model's attribute instead of producing a new date.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
}
