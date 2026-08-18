<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int $plan_id
 * @property int|null $pending_plan_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property bool $cancel_at_period_end
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ended_at
 * @property-read Customer $customer
 * @property-read Plan $plan
 * @property-read Plan|null $pendingPlan
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionChange> $changes
 */
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'customer_id',
        'plan_id',
        'pending_plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
        'ended_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The plan a scheduled downgrade will move to at the end of this period.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /**
     * @return HasMany<SubscriptionChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(SubscriptionChange::class)->latest('effective_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeGrantingAccess(Builder $query): void
    {
        $query->whereIn('status', array_values(array_filter(
            SubscriptionStatus::cases(),
            static fn (SubscriptionStatus $status): bool => $status->grantsAccess(),
        )));
    }

    /**
     * Subscriptions whose paid period has run out and that are still eligible
     * to renew. This is the query the daily scheduler runs.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDueForRenewal(Builder $query, Carbon $asOf): void
    {
        $query
            ->where('current_period_end', '<=', $asOf)
            ->whereNull('ended_at')
            ->whereIn('status', array_values(array_filter(
                SubscriptionStatus::cases(),
                static fn (SubscriptionStatus $status): bool => $status->isRenewable(),
            )));
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function hasScheduledPlanChange(): bool
    {
        return $this->pending_plan_id !== null;
    }

    /**
     * Cache key for the distributed lock that guards every mutation of this
     * subscription. Defined on the model so no caller can invent its own.
     */
    public function lockKey(): string
    {
        return "subscription:{$this->uuid}:lock";
    }
}
