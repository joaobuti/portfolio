<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Services\Subscriptions\PlanCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The plan catalogue is behind auth like everything else: the price list is
// not public in a billing API. Staff, since plans are not customer-scoped.
beforeEach(function (): void {
    actingAsStaff();
});

it('lists only active plans, in display order', function (): void {
    Plan::factory()->priced('99.90')->create(['code' => 'pro', 'sort_order' => 2]);
    Plan::factory()->priced('29.90')->create(['code' => 'basic', 'sort_order' => 1]);
    Plan::factory()->inactive()->create(['code' => 'legacy']);

    $this->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', 'basic')
        ->assertJsonPath('data.1.code', 'pro');
});

it('exposes prices as an integer and a formatted string', function (): void {
    Plan::factory()->priced('1234.56')->create(['code' => 'enterprise']);

    $this->getJson('/api/v1/plans/enterprise')
        ->assertOk()
        ->assertJsonPath('data.price.amount', 123456)
        ->assertJsonPath('data.price.currency', 'BRL')
        ->assertJsonPath('data.price.formatted', 'R$ 1.234,56');
});

it('serves the catalogue from cache on the second call', function (): void {
    Cache::forget(PlanCatalog::CACHE_KEY);
    Plan::factory()->create(['code' => 'basic']);

    $this->getJson('/api/v1/plans')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/plans')->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
});

it('invalidates the cache the moment a plan changes', function (): void {
    $plan = Plan::factory()->priced('29.90')->create(['code' => 'basic']);

    $this->getJson('/api/v1/plans')->assertJsonPath('data.0.price.amount', 2990);

    // No TTL to wait out: the observer flushes on write.
    $plan->update(['amount' => App\Support\Money::ofMajor('39.90', App\Enums\Currency::BRL)]);

    $this->getJson('/api/v1/plans')->assertJsonPath('data.0.price.amount', 3990);
});

it('404s an unknown plan code', function (): void {
    $this->getJson('/api/v1/plans/nope')->assertNotFound();
});
