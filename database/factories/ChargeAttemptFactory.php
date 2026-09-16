<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PaymentFailureReason;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeAttempt>
 */
final class ChargeAttemptFactory extends Factory
{
    protected $model = ChargeAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'succeeded' => true,
            'failure_reason' => null,
            'gateway_code' => null,
            'gateway_message' => null,
            'gateway_reference' => 'fake_'.fake()->uuid(),
            'amount' => Money::ofMinor(10_000, Currency::BRL),
            'attempted_at' => CarbonImmutable::now(),
        ];
    }

    public function failed(PaymentFailureReason $reason = PaymentFailureReason::Declined): self
    {
        return $this->state(fn (): array => [
            'succeeded' => false,
            'failure_reason' => $reason,
            'gateway_code' => 'fake_'.$reason->value,
            'gateway_message' => $reason->label(),
            'gateway_reference' => null,
        ]);
    }
}
