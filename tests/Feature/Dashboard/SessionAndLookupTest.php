<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    freezeClockAt('2026-11-23 12:00:00');
    config(['sanctum.stateful' => ['localhost']]);
});

it('logs into a rotated HttpOnly browser session without issuing a token', function (): void {
    $user = User::factory()->create(['email' => 'browser@example.test']);
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $old = session()->getId();
    $response = $this->postJson('/api/v1/auth/session', ['email' => $user->email, 'password' => 'password']);
    $response->assertOk()->assertJsonPath('data.uuid', $user->uuid)
        ->assertJsonPath('data.abilities', TokenAbility::toValues($user->role->defaultAbilities()));
    expect(session()->getId())->not->toBe($old)->and($user->tokens()->count())->toBe(0);
    $cookies = $response->headers->getCookies();
    $cookie = collect($cookies)->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));
    expect($cookie?->isHttpOnly())->toBeTrue();
    $this->withHeader('Origin', 'http://localhost')->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.uuid', $user->uuid);
    $this->deleteJson('/api/v1/auth/session')->assertNoContent();
    Auth::forgetGuards();
    $this->withHeader('Origin', 'http://localhost')->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('requires a real CSRF token for browser login and logout outside the testing bypass', function (): void {
    $user = User::factory()->create();
    app()->instance('env', 'local');
    $this->postJson('/api/v1/auth/session', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
    $this->withSession(['_token' => 'browser-csrf'])->withHeader('X-CSRF-TOKEN', 'browser-csrf')
        ->postJson('/api/v1/auth/session', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->withHeader('X-CSRF-TOKEN', 'stale')->deleteJson('/api/v1/auth/session')->assertStatus(419);
});

it('does not reveal whether browser login failed on password or account lookup', function (): void {
    User::factory()->create(['email' => 'exists@example.test']);
    $a = $this->postJson('/api/v1/auth/session', ['email' => 'exists@example.test', 'password' => 'wrong']);
    $b = $this->postJson('/api/v1/auth/session', ['email' => 'unknown@example.test', 'password' => 'wrong']);
    $a->assertUnauthorized();
    expect($b->status())->toBe($a->status())->and($b->json('error'))->toBe($a->json('error'));
});

it('enforces the staff session read-only ceiling including quote previews and token minting', function (): void {
    $user = User::factory()->staff()->create();
    $subscription = Subscription::factory()->create();
    $plan = Plan::factory()->create();
    $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost');
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.abilities', [
        'plans:read', 'subscriptions:read', 'invoices:read', 'customers:read',
    ]);
    $this->getJson('/api/v1/customers')->assertOk();
    $this->getJson('/api/v1/subscriptions/'.$subscription->uuid)->assertOk();
    $this->postJson('/api/v1/subscriptions/'.$subscription->uuid.'/plan/preview', ['plan' => $plan->code])->assertForbidden();
    $this->deleteJson('/api/v1/subscriptions/'.$subscription->uuid)->assertForbidden();
    $this->postJson('/api/v1/subscriptions', [])->assertForbidden();
    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
    $this->getJson('/api/v1/metrics')->assertForbidden();
    $this->deleteJson('/api/v1/auth/tokens/current')->assertForbidden();
});

it('discovers only the customer subscriptions and invoices and returns an empty state', function (): void {
    $mine = Customer::factory()->create();
    $other = Subscription::factory()->create();
    actingAsCustomerUser($mine);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(0, 'data');
    $subscription = Subscription::factory()->create(['customer_id' => $mine->id]);
    Invoice::factory()->create(['customer_id' => $mine->id, 'subscription_id' => $subscription->id]);
    Invoice::factory()->create(['customer_id' => $other->customer_id, 'subscription_id' => $other->id]);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $subscription->uuid);
    $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/invoices?customer='.$other->customer->uuid)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/customers')->assertForbidden();
});

it('keeps bearer scopes narrow and supports paginated read-only customer inquiry', function (): void {
    Customer::factory()->count(23)->create();
    actingAsStaff(['invoices:read']);
    $this->getJson('/api/v1/customers')->assertForbidden();
    actingAsStaff(['customers:read']);
    $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
    $this->getJson('/api/v1/customers?page=2')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/invoices')->assertForbidden();
    $this->getJson('/api/v1/customers?q='.str_repeat('x', 121))->assertUnprocessable();
    $this->getJson('/api/v1/customers?page=0')->assertUnprocessable();
});

it('returns the scheduled future subtotal instead of the current plan amount', function (): void {
    $current = Plan::factory()->create(['amount' => App\Support\Money::ofMinor(20000, 'BRL')]);
    $future = Plan::factory()->create(['amount' => App\Support\Money::ofMinor(10000, 'BRL')]);
    $subscription = Subscription::factory()->create(['plan_id' => $current->id, 'pending_plan_id' => $future->id]);
    actingAsCustomerUser($subscription->customer);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('data.0.next_charge.plan', $future->code)
        ->assertJsonPath('data.0.next_charge.subtotal.amount', 10000)->assertJsonPath('data.0.next_charge.estimated', true);
    $subscription->update(['cancel_at_period_end' => true]);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('data.0.next_charge', null);
});
