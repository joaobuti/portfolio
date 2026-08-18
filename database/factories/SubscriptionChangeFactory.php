<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PlanChangeType;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\CarbonImmutable;

/**
 * @extends Factory<SubscriptionChange>
 */
final class SubscriptionChangeFactory extends Factory
{
    protected $model = SubscriptionChange::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'from_plan_id' => Plan::factory(),
            'to_plan_id' => Plan::factory(),
            'type' => PlanChangeType::Upgrade,
            'proration_amount' => Money::ofMinor(3_500, Currency::BRL),
            'days_in_period' => 30,
            'days_remaining' => 15,
            'effective_at' => CarbonImmutable::now(),
        ];
    }
}
