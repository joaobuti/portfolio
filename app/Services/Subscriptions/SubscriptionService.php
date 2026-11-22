<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\DTOs\Pricing\PricedChange;
use App\DTOs\Subscriptions\ChangePlanData;
use App\DTOs\Subscriptions\CreateSubscriptionData;
use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\PlanChangeType;
use App\Enums\SubscriptionStatus;
use App\Events\Subscriptions\SubscriptionBillingPeriodStarted;
use App\Events\Subscriptions\SubscriptionCanceled;
use App\Events\Subscriptions\SubscriptionCreated;
use App\Events\Subscriptions\SubscriptionExpired;
use App\Events\Subscriptions\SubscriptionPlanChanged;
use App\Events\Subscriptions\SubscriptionRenewed;
use App\Exceptions\Subscriptions\DuplicateActiveSubscriptionException;
use App\Exceptions\Subscriptions\InvalidStatusTransitionException;
use App\Exceptions\Subscriptions\PlanChangeNotAllowedException;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlanChangeQuote;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Services\Pricing\PricingClient;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every state change a subscription can undergo.
 *
 * This is the S in MVCS and the only place allowed to mutate a subscription's
 * status, plan or period. Controllers, jobs and console commands all come
 * through here, which is what makes the invariants below actually hold rather
 * than hold "in the paths we remembered to check".
 *
 * Three things are deliberate:
 *
 * - **Every mutation runs under a distributed lock.** A customer clicking
 *   "upgrade" twice, or a retried queue job racing a web request, must not
 *   produce two prorated charges.
 * - **Every status change goes through `transitionTo()`.** The enum owns the
 *   transition table; this class never compares statuses by hand.
 * - **The service reads the clock; the calculator does not.** Time enters the
 *   module at exactly one point, so tests control it by swapping one binding.
 */
final readonly class SubscriptionService
{
    /** Seconds a lock is held before it self-releases if a worker dies. */
    private const int LOCK_TTL = 10;

    /** Seconds a caller waits for a contended lock before giving up. */
    private const int LOCK_WAIT = 5;

    public function __construct(
        private ProrationCalculator $proration,
        private Clock $clock,
        private PricingClient $pricing,
        private BillingCalendar $calendar,
    ) {}

    /**
     * Open a new subscription for a customer.
     *
     * @throws DuplicateActiveSubscriptionException
     */
    public function create(CreateSubscriptionData $data): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Cache::lock('customer-subscription:'.$data->customerUuid, 30)->block(10,
            fn (): Subscription => $this->createUnderCustomerLock($data));

        return $subscription;
    }

    /**
     * What a plan change would cost, without changing anything.
     *
     * Exposed as its own endpoint so the UI can show the customer the number
     * before they commit. It shares the proration calculator with the real
     * change. The priced preview below persists the binding quote.
     */
    public function previewPlanChange(Subscription $subscription, string $planCode, bool $applyDowngradeImmediately = false): ProrationResult
    {
        $newPlan = $this->resolveActivePlan($planCode);
        $this->assertPlanChangeIsAllowed($subscription, $newPlan);

        return $newPlan->isMoreExpensiveThan($subscription->plan) || $applyDowngradeImmediately
            ? $this->prorateChange($subscription, $newPlan)
            : ProrationResult::none(Money::zero($subscription->plan->amount->currency));
    }

    /**
     * The same preview, with regional tax and coupons applied.
     *
     * A separate method rather than an argument on {@see previewPlanChange()}
     * because the two have different failure profiles and different test
     * needs: proration is local arithmetic that always succeeds, while this
     * crosses a network and can come back degraded. Keeping them apart is what
     * lets the proration tests stay dependency-free.
     *
     * The quote is taken on the **amount due**, which may be a credit. A
     * negative amount is taxed symmetrically by the pricing service — see
     * ADR-0005.
     *
     * @throws PlanChangeNotAllowedException
     */
    public function quotePlanChange(
        Subscription $subscription,
        string $planCode,
        ?string $couponCode = null,
        bool $applyDowngradeImmediately = false,
    ): PricedChange {
        $target = $this->resolveActivePlan($planCode);
        $this->assertPlanChangeIsAllowed($subscription, $target);
        $isUpgrade = $target->isMoreExpensiveThan($subscription->plan);
        $applyNow = $isUpgrade || $applyDowngradeImmediately;
        $proration = $applyNow ? $this->prorateChange($subscription, $target)
            : ProrationResult::none(Money::zero($subscription->plan->amount->currency));
        $quote = $this->pricing->quote($proration->amountDue, $this->defaultRegion(), $couponCode);
        $stored = PlanChangeQuote::query()->create([
            'subscription_id' => $subscription->id, 'from_plan_id' => $subscription->plan_id, 'to_plan_id' => $target->id,
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end,
            'subtotal_cents' => $quote->subtotal->minorUnits, 'discount_cents' => $quote->discount->minorUnits,
            'tax_cents' => $quote->tax->minorUnits, 'total_cents' => $quote->total->minorUnits,
            'credit_cents' => $proration->creditFromCurrentPlan->minorUnits, 'charge_cents' => $proration->chargeForNewPlan->minorUnits,
            'days_total' => $proration->daysInPeriod, 'days_remaining' => $proration->daysRemaining,
            'currency' => $quote->total->currency->value, 'region' => $this->defaultRegion(),
            'coupon_code' => $couponCode, 'degraded' => $quote->isDegraded(),
            'is_upgrade' => $isUpgrade,
            'apply_immediately' => $applyNow,
        ]);

        return new PricedChange($proration, $quote, $stored->uuid);
    }

    /**
     * Move a subscription to a different plan.
     *
     * Upgrades apply immediately and are charged pro rata. Downgrades are
     * queued for the end of the current period, because the customer has
     * already paid for the more expensive plan and taking it away early would
     * mean owing them a refund.
     *
     * @throws PlanChangeNotAllowedException
     */
    public function changePlan(Subscription $subscription, ChangePlanData $data): Subscription
    {
        return $this->withLock($subscription, function () use ($subscription, $data): Subscription {
            $subscription->refresh();
            $newPlan = $this->resolveActivePlan($data->planCode);
            $stored = $data->quoteId === null ? null : PlanChangeQuote::query()
                ->where('uuid', $data->quoteId)->where('subscription_id', $subscription->id)->first();
            if ($data->quoteId !== null && $stored === null) {
                throw PlanChangeNotAllowedException::invalidQuote();
            }
            if ($stored !== null && $stored->to_plan_id !== $newPlan->id) {
                throw PlanChangeNotAllowedException::invalidQuote();
            }
            if ($stored !== null && $stored->applied_change_id !== null) {
                return $subscription;
            }
            $this->assertPlanChangeIsAllowed($subscription, $newPlan);
            if ($stored !== null && ($stored->from_plan_id !== $subscription->plan_id
                || $stored->to_plan_id !== $newPlan->id || ! $stored->period_start->eq($subscription->current_period_start)
                || ! $stored->period_end->eq($subscription->current_period_end) || $stored->period_end <= $this->clock->now()
                || $stored->apply_immediately !== ($stored->is_upgrade || $data->applyDowngradeImmediately)
                || ($data->couponCode !== null && $data->couponCode !== $stored->coupon_code))) {
                throw PlanChangeNotAllowedException::invalidQuote();
            }
            $currentPlan = $subscription->plan;

            $isUpgrade = $stored !== null ? $stored->is_upgrade : $newPlan->isMoreExpensiveThan($currentPlan);
            $type = $isUpgrade ? PlanChangeType::Upgrade : PlanChangeType::Downgrade;
            $applyNow = $isUpgrade || $data->applyDowngradeImmediately;

            $proration = $applyNow
                ? $this->prorateChange($subscription, $newPlan)
                : ProrationResult::none(Money::zero($currentPlan->amount->currency));

            $proration = $stored?->proration() ?? $proration;
            $coupon = $stored !== null ? $stored->coupon_code : $data->couponCode;
            $region = $stored !== null ? $stored->region : $this->defaultRegion();
            $quote = $stored?->quote() ?? ($isUpgrade ? $this->pricing->quote($proration->amountDue, $region, $coupon) : null);
            $previousPending = $subscription->pending_plan_id;
            $change = DB::transaction(function () use ($subscription, $currentPlan, $newPlan, $type, $applyNow, $proration, $stored, $isUpgrade, $previousPending, $quote, $coupon, $region): SubscriptionChange {
                if ($applyNow) {
                    $subscription->plan_id = $newPlan->id;
                    $subscription->pending_plan_id = null;
                } else {
                    $subscription->pending_plan_id = $newPlan->id;
                }

                $subscription->save();

                $change = $subscription->changes()->create([
                    'payment_status' => $isUpgrade ? 'pending' : null,
                    'previous_pending_plan_id' => $previousPending,
                    'from_plan_id' => $currentPlan->id,
                    'to_plan_id' => $newPlan->id,
                    'type' => $type,
                    'proration_amount' => $proration->amountDue,
                    'days_in_period' => $proration->daysInPeriod,
                    'days_remaining' => $proration->daysRemaining,
                    'effective_at' => $applyNow
                        ? $this->clock->now()
                        : $subscription->current_period_end,
                ]);
                $stored?->update(['applied_change_id' => $change->id]);
                SubscriptionPlanChanged::dispatch($subscription->refresh(), $currentPlan, $newPlan, $type,
                    $proration, $change->id, $quote, $coupon, $region);

                return $change;
            });

            return $subscription;
        });
    }

    /**
     * End a subscription, either now or when the paid period runs out.
     *
     * The default is at period end: the customer paid through that date and
     * cutting them off early is a refund we do not owe and a support ticket we
     * do not want.
     */
    public function cancel(Subscription $subscription, bool $immediately = false): Subscription
    {
        return $this->withLock($subscription, function () use ($subscription, $immediately): Subscription {
            $subscription->refresh();
            $now = $this->clock->now();
            $immediately = $immediately || $subscription->status === SubscriptionStatus::PendingPayment;

            DB::transaction(function () use ($subscription, $immediately, $now): void {
                $subscription->canceled_at = $now;
                $subscription->pending_plan_id = null;

                if ($immediately) {
                    $this->transitionTo($subscription, SubscriptionStatus::Canceled);
                    $subscription->cancel_at_period_end = false;
                    $subscription->ended_at = $now;
                } else {
                    $subscription->cancel_at_period_end = true;
                }

                $subscription->save();
            });

            SubscriptionCanceled::dispatch($subscription->refresh(), $immediately);

            return $subscription;
        });
    }

    /**
     * Roll a subscription into its next billing period.
     *
     * Called by the queue worker, one job per subscription. Idempotent by
     * construction: it re-reads the row under a lock and returns untouched if
     * the period has already moved on, so a retried job cannot double-advance
     * a customer's dates.
     */
    public function renew(Subscription $subscription): Subscription
    {
        [$result, $event] = $this->withLock($subscription, fn (): array => $this->renewLocked($subscription));
        if ($event instanceof SubscriptionRenewed) {
            event($event);
        }

        return $result;
    }

    /**
     * Record that a charge failed and the subscription is in dunning.
     */
    public function markPastDue(Subscription $subscription): Subscription
    {
        return $this->withLock($subscription, function () use ($subscription): Subscription {
            $subscription->refresh();

            DB::transaction(function () use ($subscription): void {
                $this->transitionTo($subscription, SubscriptionStatus::PastDue);
                $subscription->save();
            });

            return $subscription->refresh();
        });
    }

    /**
     * Record that a previously failed charge has now succeeded.
     */
    public function markActive(Subscription $subscription): Subscription
    {
        return $this->withLock($subscription, function () use ($subscription): Subscription {
            $subscription->refresh();

            DB::transaction(function () use ($subscription): void {
                $this->transitionTo($subscription, SubscriptionStatus::Active);
                $subscription->save();
            });

            return $subscription->refresh();
        });
    }

    public function revertPlanChange(SubscriptionChange $change): void
    {
        $subscription = Subscription::query()->findOrFail($change->subscription_id);
        $this->withLock($subscription, function () use ($subscription, $change): void {
            $subscription->refresh();
            $change->refresh();
            if ($change->payment_status !== 'pending') {
                return;
            }
            if ($subscription->plan_id !== $change->to_plan_id) {
                throw PlanChangeNotAllowedException::invalidQuote();
            }
            DB::transaction(function () use ($subscription, $change): void {
                $subscription->update(['plan_id' => $change->from_plan_id, 'pending_plan_id' => $change->previous_pending_plan_id]);
                $change->update(['payment_status' => 'reverted', 'reverted_at' => $this->clock->now()]);
                $zero = Money::zero($change->proration_amount->currency);
                $subscription->changes()->create(['from_plan_id' => $change->to_plan_id, 'to_plan_id' => $change->from_plan_id,
                    'type' => PlanChangeType::Reversion, 'proration_amount' => $zero, 'days_in_period' => 0, 'days_remaining' => 0,
                    'effective_at' => $this->clock->now()]);
                SubscriptionPlanChanged::dispatch($subscription->refresh(), $change->toPlan, $change->fromPlan,
                    PlanChangeType::Reversion, ProrationResult::none($zero));
            });
        });
    }

    private function createUnderCustomerLock(CreateSubscriptionData $data): Subscription
    {
        $customer = Customer::query()->where('uuid', $data->customerUuid)->firstOrFail();
        $plan = $this->resolveActivePlan($data->planCode);

        if ($customer->subscriptions()->whereIn('status', [SubscriptionStatus::PendingPayment, SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue])->exists()) {
            throw DuplicateActiveSubscriptionException::forCustomer($customer->uuid);
        }

        $startsAt = $data->startsAt instanceof DateTimeImmutable
            ? CarbonImmutable::instance($data->startsAt)
            : $this->clock->now();
        $onTrial = $data->withTrial && $plan->offersTrial();

        $trialEndsAt = $onTrial ? $startsAt->addDays($plan->trial_days) : null;

        $initialPeriod = $this->calendar->firstPeriod($startsAt, $plan->interval, $plan->amount);

        return DB::transaction(function () use ($customer, $plan, $onTrial, $trialEndsAt, $startsAt, $initialPeriod): Subscription {
            $subscription = Subscription::query()->create([
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'status' => $onTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::PendingPayment,
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => $startsAt,
                // During a trial the "period" is the trial window: it is what has
                // to elapse before we bill for the first time.
                'current_period_end' => $trialEndsAt ?? $initialPeriod->end,
                'cancel_at_period_end' => false,
            ]);
            SubscriptionCreated::dispatch($subscription, $onTrial ? null : $initialPeriod);

            return $subscription;
        });
    }

    /** @return array{Subscription, SubscriptionRenewed|null} */
    private function renewLocked(Subscription $subscription): array
    {
        $subscription->refresh();

        if (! $subscription->status->isRenewable() || $subscription->ended_at !== null) {
            return [$subscription, null];
        }

        if ($subscription->current_period_end > $this->clock->now()) {
            // Another worker got here first.
            return [$subscription, null];
        }

        if ($subscription->changes()->where('payment_status', 'pending')->exists()) {
            return [$subscription, null];
        }

        if ($subscription->cancel_at_period_end) {
            return [$this->expire($subscription), null];
        }

        $previousPeriodEnd = $subscription->current_period_end;
        $firstPayment = $subscription->status === SubscriptionStatus::Trialing;

        DB::transaction(function () use ($subscription, $previousPeriodEnd, $firstPayment): void {
            // A downgrade queued earlier in the period takes effect now.
            if ($subscription->pending_plan_id !== null) {
                $subscription->plan_id = $subscription->pending_plan_id;
                $subscription->pending_plan_id = null;
                $subscription->unsetRelation('plan');
            }

            // A finished trial now waits for settlement of its initial
            // invoice before granting paid access.
            if ($subscription->status === SubscriptionStatus::Trialing) {
                $this->transitionTo($subscription, SubscriptionStatus::PendingPayment);
            }

            $subscription->current_period_start = $previousPeriodEnd;

            // Wrapped rather than assigned straight through: the enum
            // deals in plain DateTimeImmutable so it stays framework-free
            // (see tests/Arch.php), while the model's attribute is a
            // CarbonImmutable. The conversion belongs here, at the seam.
            $subscription->current_period_end = CarbonImmutable::instance($this->calendar->firstPeriod($previousPeriodEnd,
                $subscription->plan->interval, $subscription->plan->amount)->end);
            $subscription->save();
            $period = $this->calendar->firstPeriod($previousPeriodEnd, $subscription->plan->interval, $subscription->plan->amount);
            SubscriptionBillingPeriodStarted::dispatch($subscription, $period, $firstPayment);
        });

        $subscription->refresh();
        $period = $this->calendar->firstPeriod($previousPeriodEnd, $subscription->plan->interval, $subscription->plan->amount);

        return [$subscription, new SubscriptionRenewed($subscription, $previousPeriodEnd, $period, $firstPayment)];

    }

    /**
     * Which tax region to price against.
     *
     * The billing core has no customer address yet, so every subscription gets
     * the configured default. This is a method rather than an inlined config
     * read so that when the address arrives, one body changes and the call
     * site above does not — it will simply start taking the subscription.
     */
    private function defaultRegion(): string
    {
        $region = config('pricing.default_region');

        return is_string($region) ? $region : '';
    }

    /**
     * Mark a subscription as ended. Terminal.
     *
     * Private because the caller must already hold the subscription's lock —
     * `renew()` is the only path that ends a subscription this way, and the
     * Redis lock is not reentrant, so a public wrapper that locked again would
     * deadlock against its own caller. Customer-initiated termination goes
     * through `cancel()`.
     */
    private function expire(Subscription $subscription): Subscription
    {
        DB::transaction(function () use ($subscription): void {
            $this->transitionTo($subscription, SubscriptionStatus::Expired);
            $subscription->ended_at = $this->clock->now();
            $subscription->pending_plan_id = null;
            $subscription->save();
        });

        SubscriptionExpired::dispatch($subscription->refresh());

        return $subscription;
    }

    /**
     * The single door every status change goes through.
     *
     * @throws InvalidStatusTransitionException
     */
    private function transitionTo(Subscription $subscription, SubscriptionStatus $target): void
    {
        if (! $subscription->status->canTransitionTo($target)) {
            throw InvalidStatusTransitionException::between($subscription->status, $target);
        }

        $subscription->status = $target;
    }

    private function prorateChange(Subscription $subscription, Plan $newPlan): ProrationResult
    {
        $timezone = $this->calendar->prorationTimezone($subscription->current_period_end);

        return $this->proration->calculate(
            currentPlanAmount: $subscription->plan->amount,
            newPlanAmount: $newPlan->amount,
            periodStart: $this->calendar->prorationStart($subscription->current_period_start,
                $subscription->current_period_end, $subscription->plan->interval)->setTimezone($timezone)->toDateTimeImmutable(),
            periodEnd: $subscription->current_period_end->setTimezone($timezone)->toDateTimeImmutable(),
            changedAt: $this->clock->now()->setTimezone($timezone)->toDateTimeImmutable(),
        );
    }

    /**
     * @throws PlanChangeNotAllowedException
     */
    private function assertPlanChangeIsAllowed(Subscription $subscription, Plan $newPlan): void
    {
        if ($subscription->changes()->where('payment_status', 'pending')->exists()) {
            throw PlanChangeNotAllowedException::pendingPayment();
        }

        if (! $subscription->status->isRenewable()) {
            throw PlanChangeNotAllowedException::statusDoesNotAllow($subscription->status->value);
        }

        if ($subscription->plan_id === $newPlan->id) {
            throw PlanChangeNotAllowedException::samePlan();
        }

        if ($subscription->plan->amount->currency !== $newPlan->amount->currency) {
            throw PlanChangeNotAllowedException::currencyChange();
        }
    }

    private function resolveActivePlan(string $code): Plan
    {
        $plan = Plan::query()->where('code', $code)->firstOrFail();

        if (! $plan->is_active) {
            throw PlanChangeNotAllowedException::inactivePlan($plan->code);
        }

        return $plan;
    }

    /**
     * Serialise all mutations of one subscription across every process.
     *
     * Redis-backed, so it holds across web nodes and queue workers alike. The
     * TTL means a worker that dies mid-change releases the lock instead of
     * wedging that subscription forever.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withLock(Subscription $subscription, Closure $callback): mixed
    {
        return Cache::lock($subscription->lockKey(), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, $callback);
    }
}
