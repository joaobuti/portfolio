<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Policies\SubscriptionPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
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
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable $current_period_start
 * @property CarbonImmutable $current_period_end
 * @property bool $cancel_at_period_end
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $ended_at
 * @property-read Customer $customer
 * @property-read Plan $plan
 * @property-read Plan|null $pendingPlan
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionChange> $changes
 */
#[Fillable([
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
])]
#[RouteKey('uuid')]
#[UsePolicy(SubscriptionPolicy::class)]
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
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
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function grantingAccess(Builder $query): void
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
    #[Scope]
    protected function dueForRenewal(Builder $query, Carbon $asOf): void
    {
        $query
            ->where('current_period_end', '<=', $asOf)
            ->whereNull('ended_at')
            ->whereIn('status', array_values(array_filter(
                SubscriptionStatus::cases(),
                static fn (SubscriptionStatus $status): bool => $status->isRenewable(),
            )));
    }
}
