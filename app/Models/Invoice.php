<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What a customer owes for one billing event.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int|null $subscription_id
 * @property InvoiceKind $kind
 * @property int|null $subscription_change_id
 * @property CarbonImmutable|null $payment_deadline_at
 * @property InvoiceStatus $status
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $tax
 * @property Money $total
 * @property string $currency
 * @property bool $pricing_degraded
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $next_attempt_at
 * @property int $attempt_count
 * @property int $pricing_recovery_attempts
 * @property CarbonImmutable|null $pricing_retry_at
 * @property CarbonImmutable|null $repriced_at
 * @property string|null $pricing_region
 * @property string|null $coupon_code
 * @property-read Customer $customer
 * @property-read Subscription|null $subscription
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChargeAttempt> $attempts
 * @property-read PaymentCheckout|null $checkout
 */
#[Fillable([
    'customer_id',
    'subscription_id',
    'status',
    'subtotal',
    'discount',
    'tax',
    'total',
    'pricing_degraded',
    'period_start',
    'period_end',
    'due_at',
    'paid_at',
    'attempt_count',
    'next_attempt_at',
    'pricing_recovery_attempts',
    'pricing_retry_at',
    'repriced_at',
    'pricing_region',
    'coupon_code',
    'kind',
    'subscription_change_id',
    'payment_deadline_at',
])]
#[RouteKey('uuid')]
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
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
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<ChargeAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ChargeAttempt::class)->latest('attempted_at');
    }

    /** @return HasOne<PaymentCheckout, $this> */
    public function checkout(): HasOne
    {
        return $this->hasOne(PaymentCheckout::class);
    }

    /**
     * Whether a charge may be attempted against this invoice right now.
     *
     * Two conditions, both non-negotiable:
     *
     * 1. The invoice is still open. Charging a paid invoice is a duplicate
     *    charge — the worst bug this module can ship.
     * 2. The amounts are not degraded. An invoice priced while the tax service
     *    was unreachable carries no tax, and billing an untaxed amount is a
     *    compliance problem that surfaces months later. See ADR-0005.
     */
    public function isChargeable(): bool
    {
        return $this->status->isChargeable() && ! $this->pricing_degraded;
    }

    /**
     * Nothing to collect — a fully discounted or zero-value invoice.
     *
     * Worth its own question because such an invoice must be settled without
     * ever touching the gateway: a zero-value charge is a fee for nothing and
     * some gateways reject it outright.
     */
    public function isZeroValue(): bool
    {
        return ! $this->total->isPositive();
    }

    /**
     * Cache key for the lock held while this invoice is charged.
     *
     * Defined on the model so no caller can invent its own, mirroring
     * {@see Subscription::lockKey()}.
     */
    public function lockKey(): string
    {
        return "invoice:{$this->uuid}:lock";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'kind' => InvoiceKind::class,
            'payment_deadline_at' => 'immutable_datetime',
            'subtotal' => MoneyCast::class.':subtotal_cents,currency',
            'discount' => MoneyCast::class.':discount_cents,currency',
            'tax' => MoneyCast::class.':tax_cents,currency',
            'total' => MoneyCast::class.':total_cents,currency',
            'pricing_degraded' => 'boolean',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'pricing_recovery_attempts' => 'integer',
            'pricing_retry_at' => 'immutable_datetime',
            'repriced_at' => 'immutable_datetime',
        ];
    }

    /**
     * Open invoices whose next attempt is due.
     *
     * The whole of the dunning sweep's query, and covered by one index. It
     * reads `next_attempt_at` rather than recomputing the retry schedule,
     * because the schedule belongs to the code that recorded the failure —
     * see App\\Services\\Billing\\BillingService.
     *
     * Degraded invoices are excluded: they carry no tax and must never be
     * charged, so sweeping them up would queue a job guaranteed to throw.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function dueForCollection(Builder $query, CarbonImmutable $asOf): void
    {
        $query
            ->where('status', InvoiceStatus::Open)
            ->where('pricing_degraded', false)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', $asOf);
    }
}
