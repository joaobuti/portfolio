<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;

it('can seed repeatedly without duplicating demo customers or overwriting plan edits', function (): void {
    app(DatabaseSeeder::class)->run();
    $plan = Plan::query()->where('code', 'starter')->firstOrFail();
    $plan->update(['amount' => Money::ofMinor(7000, Currency::BRL)]);

    app(DatabaseSeeder::class)->run();

    expect(Customer::query()->count())->toBe(1)
        ->and(Plan::query()->count())->toBe(4)
        ->and($plan->fresh()?->amount->minorUnits)->toBe(7000);
});

it('does not seed demo data in production', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    app(DatabaseSeeder::class)->run();
    expect(Customer::query()->count())->toBe(0)
        ->and(Plan::query()->count())->toBe(0);
});
