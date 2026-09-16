<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Jobs\Subscriptions\ProcessSubscriptionRenewal;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 03:00:00');
    Queue::fake();

    $this->plan = Plan::factory()->priced('29.90')->create(['code' => 'basic']);
});

function subscriptionEndingOn(string $periodEnd, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
{
    $end = CarbonImmutable::parse($periodEnd);

    return Subscription::factory()
        ->for(test()->plan, 'plan')
        ->status($status)
        ->period($end->subMonth(), $end)
        ->create();
}

it('queues one job per subscription whose period has ended', function (): void {
    $due = subscriptionEndingOn('2026-06-15');
    $alsoDue = subscriptionEndingOn('2026-06-01');
    subscriptionEndingOn('2026-07-01'); // still current

    $this->artisan('subscriptions:renew')->assertSuccessful();

    Queue::assertPushed(ProcessSubscriptionRenewal::class, 2);
    Queue::assertPushed(
        ProcessSubscriptionRenewal::class,
        fn (ProcessSubscriptionRenewal $job): bool => $job->subscriptionUuid === $due->uuid,
    );
    Queue::assertPushed(
        ProcessSubscriptionRenewal::class,
        fn (ProcessSubscriptionRenewal $job): bool => $job->subscriptionUuid === $alsoDue->uuid,
    );
});

it('sends renewal work to its own queue', function (): void {
    subscriptionEndingOn('2026-06-15');

    $this->artisan('subscriptions:renew')->assertSuccessful();

    Queue::assertPushedOn('billing', ProcessSubscriptionRenewal::class);
});

it('skips subscriptions that can no longer renew', function (SubscriptionStatus $status): void {
    subscriptionEndingOn('2026-06-15', $status);

    $this->artisan('subscriptions:renew')->assertSuccessful();

    Queue::assertNothingPushed();
})->with([
    SubscriptionStatus::Canceled,
    SubscriptionStatus::Expired,
]);

it('still renews a past due subscription', function (): void {
    subscriptionEndingOn('2026-06-15', SubscriptionStatus::PastDue);

    $this->artisan('subscriptions:renew')->assertSuccessful();

    Queue::assertPushed(ProcessSubscriptionRenewal::class, 1);
});

it('queues nothing in dry-run mode', function (): void {
    subscriptionEndingOn('2026-06-15');

    $this->artisan('subscriptions:renew', ['--dry-run' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});

it('can replay a past billing day with --as-of', function (): void {
    subscriptionEndingOn('2026-06-15');

    // As of June 10th nothing was due yet.
    $this->artisan('subscriptions:renew', ['--as-of' => '2026-06-10'])->assertSuccessful();

    Queue::assertNothingPushed();
});
