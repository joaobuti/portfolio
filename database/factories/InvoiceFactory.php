<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfDay();

        // Ends in 00, so the fake gateway succeeds unless a state says
        // otherwise. Failure has to be asked for, never stumbled into.
        $total = Money::ofMinor(10_000, Currency::BRL);

        return [
            'customer_id' => Customer::factory(),
            'subscription_id' => null,
            'status' => InvoiceStatus::Open,
            'subtotal' => $total,
            'discount' => Money::zero(Currency::BRL),
            'tax' => Money::zero(Currency::BRL),
            'total' => $total,
            'pricing_degraded' => false,
            'period_start' => $start,
            'period_end' => $start->addMonth(),
            'due_at' => $start,
            'paid_at' => null,
            'attempt_count' => 0,
        ];
    }

    /**
     * An amount the fake gateway refuses. See FakePaymentGateway for the map
     * of cents to outcomes.
     */
    public function decliningAmount(): self
    {
        return $this->totalling(10_001);
    }

    public function expiredCardAmount(): self
    {
        return $this->totalling(10_003);
    }

    public function totalling(int $minorUnits): self
    {
        $amount = Money::ofMinor($minorUnits, Currency::BRL);

        return $this->state(fn (): array => [
            'subtotal' => $amount,
            'total' => $amount,
        ]);
    }

    public function paid(): self
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Paid,
            'paid_at' => CarbonImmutable::now(),
        ]);
    }

    public function degraded(): self
    {
        return $this->state(fn (): array => ['pricing_degraded' => true]);
    }
}
