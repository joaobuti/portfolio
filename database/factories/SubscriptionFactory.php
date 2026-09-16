<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfDay();

        return [
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'pending_plan_id' => null,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $start->addMonth(),
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
        ];
    }

    public function status(SubscriptionStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A subscription whose current period runs between two given moments.
     */
    public function period(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return $this->state(fn (): array => [
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);
    }

    /**
     * Already past its period end — the state the renewal sweep looks for.
     */
    public function due(): self
    {
        $start = CarbonImmutable::now()->subMonths(2);

        return $this->state(fn (): array => [
            'current_period_start' => $start,
            'current_period_end' => $start->addMonth(),
        ]);
    }

    public function onTrial(int $days = 14): self
    {
        $start = CarbonImmutable::now()->startOfDay();

        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $start->addDays($days),
            'current_period_start' => $start,
            'current_period_end' => $start->addDays($days),
        ]);
    }

    public function cancelingAtPeriodEnd(): self
    {
        return $this->state(fn (): array => [
            'cancel_at_period_end' => true,
            'canceled_at' => CarbonImmutable::now(),
        ]);
    }
}
