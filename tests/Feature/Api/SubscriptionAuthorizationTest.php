<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * The hole this module had until auth existed.
 *
 * Every route was reachable by anyone, and `GET /subscriptions/{uuid}` handed
 * any subscription to any caller who knew — or guessed — a uuid. These tests
 * exist so that stays fixed: they are about who may reach what, and nothing
 * else.
 */
beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    $this->plan = Plan::factory()->priced('29.90')->create(['code' => 'basic']);

    $this->mine = Customer::factory()->create();
    $this->theirs = Customer::factory()->create();

    $this->mySubscription = Subscription::factory()->for($this->mine)->for($this->plan, 'plan')->create();
    $this->theirSubscription = Subscription::factory()->for($this->theirs)->for($this->plan, 'plan')->create();
});

describe('authentication', function (): void {
    // A literal uuid rather than one from the fixtures: Pest resolves datasets
    // outside the test object, so `$this` is not available here. It does not
    // matter — the auth middleware runs before route model binding, so an
    // unauthenticated caller is refused before the id is ever looked up. That
    // ordering is itself worth pinning.
    it('refuses every route without a token', function (string $method, string $uri): void {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/v1/plans'],
        ['POST', '/api/v1/subscriptions'],
        ['GET', '/api/v1/subscriptions/01a05cff-0000-7000-8000-000000000001'],
        ['DELETE', '/api/v1/subscriptions/01a05cff-0000-7000-8000-000000000001'],
        ['PUT', '/api/v1/subscriptions/01a05cff-0000-7000-8000-000000000001/plan'],
    ]);
});

describe('ownership', function (): void {
    it('lets a customer read their own subscription', function (): void {
        actingAsCustomerUser($this->mine);

        $this->getJson("/api/v1/subscriptions/{$this->mySubscription->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->mySubscription->uuid);
    });

    it('refuses a customer reading someone else\'s subscription', function (): void {
        actingAsCustomerUser($this->mine);

        $this->getJson("/api/v1/subscriptions/{$this->theirSubscription->uuid}")
            ->assertForbidden();
    });

    it('refuses a customer cancelling someone else\'s subscription', function (): void {
        actingAsCustomerUser($this->mine);

        $this->deleteJson("/api/v1/subscriptions/{$this->theirSubscription->uuid}")
            ->assertForbidden();

        expect($this->theirSubscription->fresh()?->canceled_at)->toBeNull();
    });

    it('refuses a customer changing someone else\'s plan', function (): void {
        actingAsCustomerUser($this->mine);
        Plan::factory()->priced('99.90')->create(['code' => 'pro']);

        $this->putJson("/api/v1/subscriptions/{$this->theirSubscription->uuid}/plan", [
            'plan' => 'pro',
        ])->assertForbidden();

        expect($this->theirSubscription->fresh()?->plan_id)->toBe($this->plan->id);
    });

    it('refuses a customer previewing someone else\'s plan change', function (): void {
        // A preview writes nothing, but it discloses what another customer
        // pays — which is exactly as private as the change itself.
        actingAsCustomerUser($this->mine);
        Plan::factory()->priced('99.90')->create(['code' => 'pro']);

        $this->postJson("/api/v1/subscriptions/{$this->theirSubscription->uuid}/plan/preview", [
            'plan' => 'pro',
        ])->assertForbidden();
    });

    it('refuses opening a subscription for another customer', function (): void {
        actingAsCustomerUser($this->mine);

        $this->postJson('/api/v1/subscriptions', [
            'customer' => $this->theirs->uuid,
            'plan' => 'basic',
        ])->assertForbidden();
    });

    it('does not reveal whether an unknown customer exists', function (): void {
        // 403, not 404: a different status here would let a caller walk the
        // uuid space and learn which customers are real.
        actingAsCustomerUser($this->mine);

        $this->postJson('/api/v1/subscriptions', [
            'customer' => '00000000-0000-4000-8000-000000000000',
            'plan' => 'basic',
        ])->assertForbidden();
    });

    it('lets staff reach any customer', function (): void {
        actingAsStaff();

        $this->getJson("/api/v1/subscriptions/{$this->theirSubscription->uuid}")->assertOk();
        $this->getJson("/api/v1/subscriptions/{$this->mySubscription->uuid}")->assertOk();
    });
});

describe('token abilities', function (): void {
    it('refuses a write with a read-only token', function (): void {
        actingAsStaff([TokenAbility::SubscriptionsRead->value]);

        $this->deleteJson("/api/v1/subscriptions/{$this->mySubscription->uuid}")
            ->assertForbidden();

        expect($this->mySubscription->fresh()?->canceled_at)->toBeNull();
    });

    it('allows the read that the same token does cover', function (): void {
        actingAsStaff([TokenAbility::SubscriptionsRead->value]);

        $this->getJson("/api/v1/subscriptions/{$this->mySubscription->uuid}")->assertOk();
    });

    it('refuses the plan catalogue without plans:read', function (): void {
        actingAsStaff([TokenAbility::SubscriptionsRead->value]);

        $this->getJson('/api/v1/plans')->assertForbidden();
    });

    it('separates ownership from scope', function (): void {
        // Owning the resource is not enough if the credential does not carry
        // the power. The two checks are independent by design.
        actingAsCustomerUser($this->mine, [TokenAbility::PlansRead->value]);

        $this->getJson("/api/v1/subscriptions/{$this->mySubscription->uuid}")
            ->assertForbidden();
    });
});
