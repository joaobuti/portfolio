<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\DTOs\Subscriptions\ChangePlanData;
use App\DTOs\Subscriptions\CreateSubscriptionData;
use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\PlanChangeType;
use App\Enums\SubscriptionStatus;
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
use App\Models\Subscription;
use App\Support\Clock;
use App\Support\Money;
use Closure;
use Illuminate\Support\CarbonImmutable;
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
    private const LOCK_TTL = 10;

    /** Seconds a caller waits for a contended lock before giving up. */
    private const LOCK_WAIT = 5;

    public function __construct(
        private ProrationCalculator $proration,
        private Clock $clock,
    ) {}

    /**
     * Open a new subscription for a customer.
     *
     * @throws DuplicateActiveSubscriptionException
     */
    public function create(CreateSubscriptionData $data): Subscription
    {
        $customer = Customer::query()->where('uuid', $data->customerUuid)->firstOrFail();
        $plan = $this->resolveActivePlan($data->planCode);

        if ($customer->activeSubscriptions()->exists()) {
            throw DuplicateActiveSubscriptionException::forCustomer($customer->uuid);
        }

        $startsAt = $data->startsAt !== null
            ? CarbonImmutable::instance($data->startsAt)
            : $this->clock->now();
        $onTrial = $data->withTrial && $plan->offersTrial();

        $trialEndsAt = $onTrial ? $startsAt->addDays($plan->trial_days) : null;

        $subscription = DB::transaction(static fn (): Subscription => Subscription::query()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => $onTrial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $startsAt,
            // During a trial the "period" is the trial window: it is what has
            // to elapse before we bill for the first time.
            'current_period_end' => $trialEndsAt ?? $plan->interval->advance($startsAt),
            'cancel_at_period_end' => false,
        ]));

        SubscriptionCreated::dispatch($subscription);

        return $subscription;
    }

    /**
     * What a plan change would cost, without changing anything.
     *
     * Exposed as its own endpoint so the UI can show the customer the number
     * before they commit. Sharing the code path with the real change is the
     * point — a preview that can disagree with the charge is worse than no
     * preview.
     */
    public function previewPlanChange(Subscription $subscription, string $planCode): ProrationResult
    {
        $newPlan = $this->resolveActivePlan($planCode);
        $this->assertPlanChangeIsAllowed($subscription, $newPlan);

        return $this->prorateChange($subscription, $newPlan);
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
        $newPlan = $this->resolveActivePlan($data->planCode);
        $this->assertPlanChangeIsAllowed($subscription, $newPlan);

        return $this->withLock($subscription, function () use ($subscription, $newPlan, $data): Subscription {
            $subscription->refresh();
            $currentPlan = $subscription->plan;

            $isUpgrade = $newPlan->isMoreExpensiveThan($currentPlan);
            $type = $isUpgrade ? PlanChangeType::Upgrade : PlanChangeType::Downgrade;
            $applyNow = $isUpgrade || $data->applyDowngradeImmediately;

            $proration = $applyNow
                ? $this->prorateChange($subscription, $newPlan)
                : ProrationResult::none(Money::zero($currentPlan->amount->currency));

            DB::transaction(function () use ($subscription, $currentPlan, $newPlan, $type, $applyNow, $proration): void {
                if ($applyNow) {
                    $subscription->plan_id = $newPlan->id;
                    $subscription->pending_plan_id = null;
                } else {
                    $subscription->pending_plan_id = $newPlan->id;
                }

                $subscription->save();

                $subscription->changes()->create([
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
            });

            SubscriptionPlanChanged::dispatch(
                $subscription->refresh(),
                $currentPlan,
                $newPlan,
                $type,
                $proration,
            );

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
        return $this->withLock($subscription, function () use ($subscription): Subscription {
            $subscription->refresh();

            if (! $subscription->status->isRenewable() || $subscription->ended_at !== null) {
                return $subscription;
            }

            if ($subscription->current_period_end->isFuture()) {
                // Another worker got here first.
                return $subscription;
            }

            if ($subscription->cancel_at_period_end) {
                return $this->expire($subscription);
            }

            $previousPeriodEnd = $subscription->current_period_end;

            DB::transaction(function () use ($subscription, $previousPeriodEnd): void {
                // A downgrade queued earlier in the period takes effect now.
                if ($subscription->pending_plan_id !== null) {
                    $subscription->plan_id = $subscription->pending_plan_id;
                    $subscription->pending_plan_id = null;
                    $subscription->unsetRelation('plan');
                }

                // The first renewal after a trial is what turns it into a
                // paying subscription.
                if ($subscription->status === SubscriptionStatus::Trialing) {
                    $this->transitionTo($subscription, SubscriptionStatus::Active);
                }

                $subscription->current_period_start = $previousPeriodEnd;
                $subscription->current_period_end = $subscription->plan->interval->advance($previousPeriodEnd);
                $subscription->save();
            });

            SubscriptionRenewed::dispatch($subscription->refresh(), $previousPeriodEnd);

            return $subscription;
        });
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
        return $this->proration->calculate(
            currentPlanAmount: $subscription->plan->amount,
            newPlanAmount: $newPlan->amount,
            periodStart: $subscription->current_period_start->toDateTimeImmutable(),
            periodEnd: $subscription->current_period_end->toDateTimeImmutable(),
            changedAt: $this->clock->now()->toDateTimeImmutable(),
        );
    }

    /**
     * @throws PlanChangeNotAllowedException
     */
    private function assertPlanChangeIsAllowed(Subscription $subscription, Plan $newPlan): void
    {
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
