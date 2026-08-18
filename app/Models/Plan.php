<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BillingInterval;
use App\Support\Money;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property Money $amount
 * @property int $amount_cents
 * @property string $currency
 * @property BillingInterval $interval
 * @property int $trial_days
 * @property bool $is_active
 * @property array<string, mixed> $features
 * @property int $sort_order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'description',
        'amount',
        'interval',
        'trial_days',
        'is_active',
        'features',
        'sort_order',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'interval' => BillingInterval::class,
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'features' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('amount_cents');
    }

    public function offersTrial(): bool
    {
        return $this->trial_days > 0;
    }

    public function isMoreExpensiveThan(self $other): bool
    {
        return $this->amount->greaterThan($other->amount);
    }
}
