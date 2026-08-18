<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, asText: true),
            'description' => fake()->sentence(),
            'amount' => Money::ofMinor(fake()->numberBetween(1_000, 50_000), Currency::BRL),
            'interval' => BillingInterval::Monthly,
            'trial_days' => 0,
            'is_active' => true,
            'features' => [],
            'sort_order' => 0,
        ];
    }

    /**
     * Fix the price, so a test that cares about arithmetic reads as arithmetic.
     */
    public function priced(string $major, Currency $currency = Currency::BRL): self
    {
        return $this->state(fn (): array => [
            'amount' => Money::ofMajor($major, $currency),
        ]);
    }

    public function withTrial(int $days = 14): self
    {
        return $this->state(fn (): array => ['trial_days' => $days]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function interval(BillingInterval $interval): self
    {
        return $this->state(fn (): array => ['interval' => $interval]);
    }
}
