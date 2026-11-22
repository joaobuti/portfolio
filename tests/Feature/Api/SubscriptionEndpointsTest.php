<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    $this->customer = Customer::factory()->create();

    // These tests are about subscription behaviour, not authorisation, so they
    // run as a user who legitimately owns the customer under test. Who may
    // reach whose data is asserted in SubscriptionAuthorizationTest.
    $this->user = actingAsCustomerUser($this->customer);

    $this->basic = Plan::factory()->priced('29.90')->create(['code' => 'basic']);
    $this->pro = Plan::factory()->priced('99.90')->create(['code' => 'pro']);
});

describe('POST /api/v1/subscriptions', function (): void {
    it('creates a subscription and returns 201', function (): void {
        $this->postJson('/api/v1/subscriptions', [
            'customer' => $this->customer->uuid,
            'plan' => 'basic',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'pending_payment')
            ->assertJsonPath('data.plan.code', 'basic')
            ->assertJsonPath('data.plan.price.amount', 2990)
            ->assertJsonPath('data.plan.price.formatted', 'R$ 29,90');
    });

    it('rejects an unknown plan with a validation error', function (): void {
        $this->postJson('/api/v1/subscriptions', [
            'customer' => $this->customer->uuid,
            'plan' => 'does-not-exist',
        ])->assertUnprocessable()->assertJsonValidationErrors('plan');
    });

    it('rejects a retired plan without revealing that it exists', function (): void {
        Plan::factory()->inactive()->create(['code' => 'legacy']);

        $this->postJson('/api/v1/subscriptions', [
            'customer' => $this->customer->uuid,
            'plan' => 'legacy',
        ])->assertUnprocessable()->assertJsonValidationErrors('plan');
    });

    it('returns a structured domain error when the customer already subscribes', function (): void {
        Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $this->postJson('/api/v1/subscriptions', [
            'customer' => $this->customer->uuid,
            'plan' => 'pro',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.duplicate_active');
    });
});

describe('plan changes', function (): void {
    beforeEach(function (): void {
        $this->subscription = Subscription::factory()
            ->for($this->customer)
            ->for($this->basic, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();
    });

    it('previews an upgrade without changing anything', function (): void {
        $this->postJson("/api/v1/subscriptions/{$this->subscription->uuid}/plan/preview", [
            'plan' => 'pro',
        ])
            ->assertOk()
            ->assertJsonPath('data.amount_due.amount', 3500)
            ->assertJsonPath('data.amount_due.formatted', 'R$ 35,00')
            ->assertJsonPath('data.period.days_remaining', 15)
            ->assertJsonPath('data.is_charge', true);

        expect($this->subscription->fresh()?->plan_id)->toBe($this->basic->id);
    });

    it('applies an upgrade', function (): void {
        $quote = $this->postJson("/api/v1/subscriptions/{$this->subscription->uuid}/plan/preview", ['plan' => 'pro'])->assertOk()->json('data.quote_id');
        $this->putJson("/api/v1/subscriptions/{$this->subscription->uuid}/plan", ['plan' => 'pro', 'quote_id' => $quote])
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'pro')
            ->assertJsonPath('data.pending_plan', null);
    });

    it('queues a downgrade and says so in the payload', function (): void {
        // Its own customer, because this block's beforeEach already gave
        // $this->customer an active subscription and the partial unique index
        // permits exactly one. Staff, so the test stays about downgrade
        // mechanics rather than about who owns what.
        actingAsStaff();

        $subscription = Subscription::factory()
            ->for(Customer::factory())
            ->for($this->pro, 'plan')
            ->period(
                CarbonImmutable::parse('2026-06-01'),
                CarbonImmutable::parse('2026-07-01'),
            )
            ->create();

        $quote = $this->postJson("/api/v1/subscriptions/{$subscription->uuid}/plan/preview", ['plan' => 'basic'])->assertOk()->json('data.quote_id');
        $this->putJson("/api/v1/subscriptions/{$subscription->uuid}/plan", ['plan' => 'basic', 'quote_id' => $quote])
            ->assertOk()
            ->assertJsonPath('data.plan.code', 'pro')
            ->assertJsonPath('data.pending_plan.code', 'basic');
    });

    it('returns a structured domain error for a same-plan change', function (): void {
        $this->postJson("/api/v1/subscriptions/{$this->subscription->uuid}/plan/preview", ['plan' => 'basic'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'subscription.plan_change_not_allowed')
            ->assertJsonPath('error.context.reason', 'same_plan');
    });
});

describe('DELETE /api/v1/subscriptions/{id}', function (): void {
    it('schedules cancellation for the end of the period by default', function (): void {
        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $this->deleteJson("/api/v1/subscriptions/{$subscription->uuid}")
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.status.grants_access', true);
    });

    it('ends access immediately when asked', function (): void {
        $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

        $this->deleteJson("/api/v1/subscriptions/{$subscription->uuid}", ['immediately' => true])
            ->assertOk()
            ->assertJsonPath('data.status.value', SubscriptionStatus::Canceled->value)
            ->assertJsonPath('data.status.grants_access', false);
    });
});

it('addresses subscriptions by uuid, never by database id', function (): void {
    $subscription = Subscription::factory()->for($this->customer)->for($this->basic, 'plan')->create();

    $this->getJson("/api/v1/subscriptions/{$subscription->id}")->assertNotFound();
    $this->getJson("/api/v1/subscriptions/{$subscription->uuid}")->assertOk();
});

it('echoes back the correlation id it was given', function (): void {
    $this->getJson('/api/v1/plans', [AssignCorrelationId::HEADER => 'trace-me-123'])
        ->assertOk()
        ->assertHeader(AssignCorrelationId::HEADER, 'trace-me-123');
});

it('generates a correlation id when the caller does not supply one', function (): void {
    $response = $this->getJson('/api/v1/plans')->assertOk();

    expect($response->headers->get(AssignCorrelationId::HEADER))->not->toBeEmpty();
});
