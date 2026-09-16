<?php

declare(strict_types=1);

use App\DTOs\Subscriptions\ChangePlanData;
use App\DTOs\Subscriptions\CreateSubscriptionData;
use App\Enums\PlanChangeType;
use App\Enums\SubscriptionStatus;
use App\Events\Subscriptions\SubscriptionCanceled;
use App\Events\Subscriptions\SubscriptionCreated;
use App\Events\Subscriptions\SubscriptionPlanChanged;
use App\Events\Subscriptions\SubscriptionRenewed;
use App\Exceptions\Subscriptions\DuplicateActiveSubscriptionException;
use App\Exceptions\Subscriptions\PlanChangeNotAllowedException;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    $this->service = app(SubscriptionService::class);
    $this->customer = Customer::factory()->create();
    $this->basic = Plan::factory()->priced('29.90')->create(['code' => 'basic']);
    $this->pro = Plan::factory()->priced('99.90')->create(['code' => 'pro']);
});

describe('create', function (): void {
    it('starts an active subscription when the plan has no trial', function (): void {
        Event::fake([SubscriptionCreated::class]);

        $subscription = $this->service->create(new CreateSubscriptionData(
            customerUuid: $this->customer->uuid,
            planCode: 'basic',
        ));

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->trial_ends_at)->toBeNull()
            ->and($subscription->current_period_start->toDateString())->toBe('2026-06-16')
            ->and($subscription->current_period_end->toDateString())->toBe('2026-07-16');

        Event::assertDispatched(SubscriptionCreated::class);
    });

    it('opens in trial when the plan offers one, billing only when it ends', function (): void {
        $trialPlan = Plan::factory()->priced('49.90')->withTrial(14)->create(['code' => 'trial-plan']);

        $subscription = $this->service->create(new CreateSubscriptionData(
            customerUuid: $this->customer->uuid,
            planCode: $trialPlan->code,
        ));

        expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->trial_ends_at?->toDateString())->toBe('2026-06-30')
            // The period ends when the trial does: that is the moment we bill.
            ->and($subscription->current_period_end->toDateString())->toBe('2026-06-30');
    });

    it('can skip the trial on request', function (): void {
        $trialPlan = Plan::factory()->priced('49.90')->withTrial(14)->create(['code' => 'skippable']);

        $subscription = $this->service->create(new CreateSubscriptionData(
            customerUuid: $this->customer->uuid,
            planCode: $trialPlan->code,
            withTrial: false,
        ));

        expect($subscription->status)->toBe(SubscriptionStatus::Active);
    });

    it('refuses a second subscription for a customer who already has access', function (): void {
        $this->service->create(new CreateSubscriptionData($this->customer->uuid, 'basic'));

        $this->service->create(new CreateSubscriptionData($this->customer->uuid, 'pro'));
    })->throws(DuplicateActiveSubscriptionException::class);

    it('lets a customer resubscribe once the previous subscription ended', function (): void {
        $first = $this->service->create(new CreateSubscriptionData($this->customer->uuid, 'basic'));
        $this->service->cancel($first, immediately: true);

        $second = $this->service->create(new CreateSubscriptionData($this->customer->uuid, 'pro'));

        expect($second->status)->toBe(SubscriptionStatus::Active)
            ->and($second->id)->not->toBe($first->id);
    });

    it('refuses a retired plan', function (): void {
        Plan::factory()->inactive()->create(['code' => 'legacy']);

        $this->service->create(new CreateSubscriptionData($this->customer->uuid, 'legacy'));
    })->throws(PlanChangeNotAllowedException::class);
});

describe('changePlan — upgrade', function (): void {
    it('applies immediately and charges the prorated difference', function (): void {
        Event::fake([SubscriptionPlanChanged::class]);

        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $updated = $this->service->changePlan($subscription, new ChangePlanData('pro'));

        expect($updated->plan_id)->toBe($this->pro->id)
            ->and($updated->pending_plan_id)->toBeNull();

        Event::assertDispatched(
            SubscriptionPlanChanged::class,
            fn (SubscriptionPlanChanged $event): bool => $event->type === PlanChangeType::Upgrade
                && $event->proration->amountDue->toDecimal() === '35.00',
        );
    });

    it('records the workings in the audit trail', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $this->service->changePlan($subscription, new ChangePlanData('pro'));

        $change = $subscription->changes()->firstOrFail();

        expect($change->type)->toBe(PlanChangeType::Upgrade)
            ->and($change->proration_amount->toDecimal())->toBe('35.00')
            ->and($change->days_in_period)->toBe(30)
            ->and($change->days_remaining)->toBe(15)
            ->and($change->effective_at->toDateTimeString())->toBe('2026-06-16 09:00:00');
    });
});

describe('changePlan — downgrade', function (): void {
    it('queues the change for the end of the paid period and charges nothing', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->pro, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $updated = $this->service->changePlan($subscription, new ChangePlanData('basic'));

        expect($updated->plan_id)->toBe($this->pro->id)
            ->and($updated->pending_plan_id)->toBe($this->basic->id);

        $change = $subscription->changes()->firstOrFail();

        expect($change->proration_amount->isZero())->toBeTrue()
            ->and($change->effective_at->toDateString())->toBe('2026-07-01');
    });

    it('can be forced to apply now, crediting the customer', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->pro, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $updated = $this->service->changePlan(
            $subscription,
            new ChangePlanData('basic', applyDowngradeImmediately: true),
        );

        expect($updated->plan_id)->toBe($this->basic->id)
            ->and($updated->pending_plan_id)->toBeNull()
            ->and($subscription->changes()->firstOrFail()->proration_amount->toDecimal())->toBe('-35.00');
    });
});

describe('changePlan — refusals', function (): void {
    it('refuses to move to the plan it is already on', function (): void {
        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $this->service->changePlan($subscription, new ChangePlanData('basic'));
    })->throws(PlanChangeNotAllowedException::class);

    it('refuses to change the billing currency', function (): void {
        $usdPlan = Plan::factory()->priced('19.00', App\Enums\Currency::USD)->create(['code' => 'usd-plan']);
        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $this->service->changePlan($subscription, new ChangePlanData($usdPlan->code));
    })->throws(PlanChangeNotAllowedException::class);

    it('refuses once the subscription has been canceled', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->status(SubscriptionStatus::Canceled)
            ->create();

        $this->service->changePlan($subscription, new ChangePlanData('pro'));
    })->throws(PlanChangeNotAllowedException::class);
});

describe('previewPlanChange', function (): void {
    it('returns the same number the change would charge, and writes nothing', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $preview = $this->service->previewPlanChange($subscription, 'pro');

        expect($preview->amountDue->toDecimal())->toBe('35.00')
            ->and($subscription->fresh()?->plan_id)->toBe($this->basic->id)
            ->and($subscription->changes()->count())->toBe(0);
    });
});

describe('cancel', function (): void {
    it('keeps access until the period ends by default', function (): void {
        Event::fake([SubscriptionCanceled::class]);

        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $canceled = $this->service->cancel($subscription);

        expect($canceled->cancel_at_period_end)->toBeTrue()
            ->and($canceled->status)->toBe(SubscriptionStatus::Active)
            ->and($canceled->ended_at)->toBeNull()
            ->and($canceled->canceled_at)->not->toBeNull();

        Event::assertDispatched(
            SubscriptionCanceled::class,
            fn (SubscriptionCanceled $event): bool => $event->immediately === false,
        );
    });

    it('ends access straight away when asked', function (): void {
        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $canceled = $this->service->cancel($subscription, immediately: true);

        expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
            ->and($canceled->ended_at)->not->toBeNull()
            ->and($canceled->status->grantsAccess())->toBeFalse();
    });

    it('drops any queued downgrade', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->pro, 'plan')
            ->create(['pending_plan_id' => $this->basic->id]);

        expect($this->service->cancel($subscription)->pending_plan_id)->toBeNull();
    });
});

describe('renew', function (): void {
    it('rolls the period forward', function (): void {
        Event::fake([SubscriptionRenewed::class]);

        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-05-01'),
                CarbonImmutable::parse('2026-06-01'),
            )
            ->create();

        $renewed = $this->service->renew($subscription);

        expect($renewed->current_period_start->toDateString())->toBe('2026-06-01')
            ->and($renewed->current_period_end->toDateString())->toBe('2026-07-01');

        Event::assertDispatched(SubscriptionRenewed::class);
    });

    it('converts a finished trial into a paying subscription', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->status(SubscriptionStatus::Trialing)
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-06-15'),
            )
            ->create(['trial_ends_at' => CarbonImmutable::parse('2026-06-15')]);

        expect($this->service->renew($subscription)->status)->toBe(SubscriptionStatus::Active);
    });

    it('applies a queued downgrade at the period boundary', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->pro, 'plan')
            ->period(
                CarbonImmutable::parse('2026-05-01'),
                CarbonImmutable::parse('2026-06-01'),
            )
            ->create(['pending_plan_id' => $this->basic->id]);

        $renewed = $this->service->renew($subscription);

        expect($renewed->plan_id)->toBe($this->basic->id)
            ->and($renewed->pending_plan_id)->toBeNull();
    });

    it('expires instead of renewing when cancellation was scheduled', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->cancelingAtPeriodEnd()
            ->period(
                CarbonImmutable::parse('2026-05-01'),
                CarbonImmutable::parse('2026-06-01'),
            )
            ->create();

        $result = $this->service->renew($subscription);

        expect($result->status)->toBe(SubscriptionStatus::Expired)
            ->and($result->ended_at)->not->toBeNull();
    });

    it('is idempotent — running twice does not advance the period twice', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-05-01'),
                CarbonImmutable::parse('2026-06-01'),
            )
            ->create();

        $this->service->renew($subscription);
        $second = $this->service->renew($subscription);

        // The second call sees a period end in the future and returns untouched.
        // This is what makes a retried queue job safe.
        expect($second->current_period_end->toDateString())->toBe('2026-07-01');
    });

    it('leaves a terminated subscription alone', function (): void {
        $subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->status(SubscriptionStatus::Expired)
            ->due()
            ->create(['ended_at' => CarbonImmutable::parse('2026-05-01')]);

        $before = $subscription->current_period_end;

        expect($this->service->renew($subscription)->current_period_end->toDateString())
            ->toBe($before->toDateString());
    });
});
