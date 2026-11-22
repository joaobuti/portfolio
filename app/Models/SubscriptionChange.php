<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PlanChangeType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit record of every plan change a subscription has been
 * through, including the proration workings that produced the amount.
 *
 * Billing disputes are won or lost on this table. It is append-only by
 * convention — nothing in the application updates or deletes a row.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $from_plan_id
 * @property int $to_plan_id
 * @property PlanChangeType $type
 * @property string|null $payment_status
 * @property int|null $previous_pending_plan_id
 * @property CarbonImmutable|null $reverted_at
 * @property Money $proration_amount
 * @property int $days_in_period
 * @property int $days_remaining
 * @property CarbonImmutable $effective_at
 * @property-read Subscription $subscription
 * @property-read Plan $fromPlan
 * @property-read Plan $toPlan
 */
#[Fillable([
    'subscription_id',
    'from_plan_id',
    'to_plan_id',
    'type',
    'proration_amount',
    'days_in_period',
    'days_remaining',
    'effective_at',
    'payment_status',
    'previous_pending_plan_id',
    'reverted_at',
])]
final class SubscriptionChange extends Model
{
    /** @use HasFactory<SubscriptionChangeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reverted_at' => 'immutable_datetime',
            'type' => PlanChangeType::class,
            'proration_amount' => MoneyCast::class.':proration_amount_cents,currency',
            'days_in_period' => 'integer',
            'days_remaining' => 'integer',
            'effective_at' => 'immutable_datetime',
        ];
    }
}
