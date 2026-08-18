<?php

declare(strict_types=1);

use App\Jobs\Subscriptions\ProcessSubscriptionRenewal;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 03:00:00');
    $this->plan = Plan::factory()->priced('29.90')->create(['code' => 'basic']);
});

it('renews the subscription it was given', function (): void {
    $subscription = Subscription::factory()
        ->for($this->plan, 'plan')
        ->period(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        )
        ->create();

    (new ProcessSubscriptionRenewal($subscription->uuid))->handle(app(SubscriptionService::class));

    expect($subscription->fresh()?->current_period_end->toDateString())->toBe('2026-07-01');
});

it('is safe to run twice — a retry must not double-advance the period', function (): void {
    $subscription = Subscription::factory()
        ->for($this->plan, 'plan')
        ->period(
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-06-01'),
        )
        ->create();

    $job = new ProcessSubscriptionRenewal($subscription->uuid);
    $job->handle(app(SubscriptionService::class));
    $job->handle(app(SubscriptionService::class));

    expect($subscription->fresh()?->current_period_end->toDateString())->toBe('2026-07-01');
});

it('shrugs off a subscription that was deleted after the job was queued', function (): void {
    $job = new ProcessSubscriptionRenewal((string) Str::uuid());

    expect(fn () => $job->handle(app(SubscriptionService::class)))->not->toThrow(Exception::class);
});

it('is unique per subscription so the sweep cannot stack duplicates', function (): void {
    $uuid = (string) Str::uuid();

    expect((new ProcessSubscriptionRenewal($uuid))->uniqueId())->toBe($uuid);
});

it('backs off progressively rather than hammering a broken dependency', function (): void {
    expect((new ProcessSubscriptionRenewal('any'))->backoff())->toBe([10, 30, 120, 300]);
});
