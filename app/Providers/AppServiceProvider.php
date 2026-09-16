<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Auth\SanctumTokenIssuer;
use App\Services\Auth\TokenIssuer;
use App\Services\Billing\FakePaymentGateway;
use App\Services\Billing\PaymentGateway;
use App\Services\Pricing\CircuitBreaker;
use App\Services\Pricing\HttpPricingClient;
use App\Services\Pricing\NullPricingClient;
use App\Services\Pricing\PricingClient;
use App\Support\Clock;
use App\Support\SystemClock;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The one place the application learns what time it is. Swap this
        // binding in a test and every time-dependent behaviour follows.
        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(CircuitBreaker::class, static fn (): CircuitBreaker => CircuitBreaker::fromConfig());

        // The seam that keeps Sanctum out of the authentication service. See
        // App\Services\Auth\TokenIssuer for why it is worth having.
        $this->app->singleton(TokenIssuer::class, SanctumTokenIssuer::class);

        // `fake` is the default so the stack runs with no third-party account.
        // A `match` rather than a container alias map: an unknown value must
        // fail loudly at boot, not silently resolve to something that takes
        // real money.
        $this->app->singleton(PaymentGateway::class, static fn (): PaymentGateway => match (config('billing.gateway')) {
            'fake' => new FakePaymentGateway,
            default => throw new InvalidArgumentException(
                'Unknown payment gateway ['.get_debug_type(config('billing.gateway')).'].',
            ),
        });

        // Switched off, every quote is degraded — which is a real code path,
        // not a stub. A test suite that never exercises the fallback is a test
        // suite that discovers it during an outage.
        $this->app->singleton(
            PricingClient::class,
            static fn (Application $app): PricingClient => config('pricing.enabled')
                ? $app->make(HttpPricingClient::class)
                : new NullPricingClient,
        );
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureRateLimiting();
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

        // Token issuing is the one endpoint where guessing is the attack, so
        // it gets its own much tighter bucket. Keyed on email *and* IP: on the
        // email alone, one attacker locks a victim out of their own account;
        // on the IP alone, a botnet spreads a credential-stuffing run across
        // thousands of addresses and never trips it.
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by(Str::lower($request->string('email')->toString()).'|'.$request->ip()));
    }
}
