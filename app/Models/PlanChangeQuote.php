<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Pricing\PriceQuote;
use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $subscription_id
 * @property int $from_plan_id
 * @property int $to_plan_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property int $credit_cents
 * @property int $charge_cents
 * @property int $days_total
 * @property int $days_remaining
 * @property string $currency
 * @property string $region
 * @property string|null $coupon_code
 * @property bool $is_upgrade
 * @property bool $apply_immediately
 * @property bool $degraded
 * @property int|null $applied_change_id
 */
final class PlanChangeQuote extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function proration(): ProrationResult
    {
        $currency = Currency::from($this->currency);

        return new ProrationResult($this->days_total, $this->days_remaining,
            Money::ofMinor($this->credit_cents, $currency), Money::ofMinor($this->charge_cents, $currency),
            Money::ofMinor($this->subtotal_cents, $currency));
    }

    public function quote(): PriceQuote
    {
        $currency = Currency::from($this->currency);
        $subtotal = Money::ofMinor($this->subtotal_cents, $currency);
        $discount = Money::ofMinor($this->discount_cents, $currency);

        return new PriceQuote($subtotal, $discount, $subtotal->subtract($discount),
            Money::ofMinor($this->tax_cents, $currency), Money::ofMinor($this->total_cents, $currency),
            taxRegion: $this->region, degraded: $this->degraded);
    }

    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'degraded' => 'boolean', 'is_upgrade' => 'boolean', 'apply_immediately' => 'boolean',
            'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer',
            'credit_cents' => 'integer', 'charge_cents' => 'integer', 'days_total' => 'integer', 'days_remaining' => 'integer'];
    }
}
