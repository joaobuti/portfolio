<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\ChargeResult;
use App\DTOs\Billing\VerifiedPayment;
use App\DTOs\Subscriptions\RenewalPeriod;
use App\Enums\CheckoutStatus;
use App\Enums\Currency;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoiceIssued;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceUncollectible;
use App\Events\Billing\PaymentFailed;
use App\Events\Subscriptions\SubscriptionPlanChanged;
use App\Exceptions\Billing\CheckoutException;
use App\Exceptions\Billing\InvoiceNotChargeableException;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Models\SubscriptionChange;
use App\Services\Pricing\PricingClient;
use App\Services\Subscriptions\BillingCalendar;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Issuing invoices and collecting on them.
 *
 * The counterpart to {@see SubscriptionService}: that one owns what a
 * subscription *is*, this one owns what it *costs* and whether we got paid.
 * They are separate because they change for different reasons — a proration
 * policy change touches one, a dunning policy change the other.
 *
 * Three properties, in the order they matter:
 *
 * - **An invoice is never issued twice for the same period.** Enforced by a
 *   unique index, not by a check — a retried renewal job racing itself would
 *   pass any check written in PHP. The violation is caught and treated as
 *   success, because the desired end state is "an invoice exists".
 * - **A settled invoice is never charged again.** Checked under a lock and
 *   re-read inside it. Duplicate charges are the most expensive bug here.
 * - **A degraded invoice is never charged at all.** If the pricing service was
 *   down when the amount was worked out, the invoice carries no tax; billing
 *   it would be a compliance problem discovered months later. See ADR-0005.
 */
final readonly class BillingService
{
    /** Seconds a charge lock is held before it self-releases if a worker dies. */
    private const int LOCK_TTL = 30;

    /** Seconds a caller waits for a contended charge lock before giving up. */
    private const int LOCK_WAIT = 10;

    public function __construct(
        private PaymentGateway|CheckoutGateway $gateway,
        private PricingClient $pricing,
        private SubscriptionService $subscriptions,
        private Clock $clock,
        private CheckoutService $checkouts,
        private BillingCalendar $calendar,
    ) {}

    /**
     * Issue the invoice for a subscription's current period.
     *
     * Idempotent by database constraint: called twice for the same period, the
     * second call returns the first invoice rather than creating a second or
     * throwing. That is what makes it safe to call from a queue job that may
     * be retried.
     */
    public function issueForPeriod(Subscription $subscription, ?RenewalPeriod $period = null, bool $initial = false): Invoice
    {
        $period ??= new RenewalPeriod(
            $subscription->current_period_start->toDateTimeImmutable(),
            $subscription->current_period_end->toDateTimeImmutable(),
            $subscription->plan->amount,
        );
        $existing = $this->findInvoiceForPeriod($subscription, $period);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $quote = $this->pricing->quote(
            amount: $period->amount,
            region: $this->defaultRegion(),
        );

        try {
            $invoice = DB::transaction(fn (): Invoice => Invoice::query()->create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'status' => InvoiceStatus::Open,
                'kind' => $initial ? InvoiceKind::Initial : InvoiceKind::Renewal,
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'tax' => $quote->tax,
                'total' => $quote->total,
                'pricing_degraded' => $quote->isDegraded(),
                'pricing_region' => $this->defaultRegion(),
                'period_start' => $period->start,
                'period_end' => $period->end,
                'due_at' => $dueAt = $initial ? $this->clock->now() : $this->calendar->dueAt(CarbonImmutable::instance($period->start)),
                'attempt_count' => 0,
                // First attempt is simply "when it falls due".
                'next_attempt_at' => $dueAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another worker issued it between our check and our insert. The
            // desired end state is "an invoice exists for this period", and it
            // does — so this is a success, not a failure.
            return $this->findInvoiceForPeriod($subscription, $period)
                ?? throw new RuntimeException('Invoice vanished after a unique violation.');
        }

        InvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    /**
     * Attempt to collect an invoice.
     *
     * Every path through this method records a {@see ChargeAttempt} except the
     * ones that never reach the gateway — a zero-value invoice settles without
     * a charge, and an unchargeable one throws before anything happens.
     *
     * @throws InvoiceNotChargeableException
     */
    public function collect(Invoice $invoice): Invoice
    {
        return $this->withLock($invoice, function () use ($invoice): Invoice {
            // Re-read inside the lock. The status may have changed while we
            // waited, and it is the only thing standing between a retry and a
            // duplicate charge.
            $invoice->refresh();

            if ($this->adjustmentDeadlinePassed($invoice)) {
                return $this->expireAdjustmentLocked($invoice);
            }

            $this->assertChargeable($invoice);

            if ($invoice->isZeroValue()) {
                return DB::transaction(fn (): Invoice => $this->settle($invoice));
            }

            if ($this->gateway instanceof CheckoutGateway) {
                return $this->checkouts->start($invoice, $this->gateway);
            }

            if ($invoice->checkout()->exists()) {
                throw new CheckoutException('This invoice is already assigned to hosted checkout.');
            }

            $result = $this->gateway->charge(
                amount: $invoice->total,
                customerReference: $invoice->customer->uuid,
                // Derived from the invoice and the attempt count, so a retry
                // of the same logical charge reuses the key and the gateway
                // refuses to take the money twice — while a genuine second
                // dunning attempt gets a new one.
                idempotencyKey: "invoice:{$invoice->uuid}:attempt:{$invoice->attempt_count}",
            );

            return DB::transaction(function () use ($invoice, $result): Invoice {
                $attempt = $this->recordAttempt($invoice, $result);

                return $result->succeeded
                    ? $this->markPaid($invoice, $attempt)
                    : $this->handleFailure($invoice, $attempt, $result);
            });
        });
    }

    public function checkout(Invoice $invoice): Invoice
    {
        if (! $this->gateway instanceof CheckoutGateway) {
            throw new CheckoutException('Hosted checkout is not enabled.');
        }
        if ($invoice->status === InvoiceStatus::Paid && $invoice->checkout()->exists()) {
            return $invoice->load('checkout');
        }

        return $this->collect($invoice);
    }

    public function repriceDegraded(Invoice $invoice): bool
    {
        return $this->withLock($invoice, function () use ($invoice): bool {
            $invoice->refresh();
            if ($invoice->status !== InvoiceStatus::Open || ! $invoice->pricing_degraded
                || ($invoice->pricing_retry_at !== null && $invoice->pricing_retry_at > $this->clock->now())
                || $invoice->checkout()->exists() || $invoice->attempts()->exists()) {
                return false;
            }
            if ($this->adjustmentDeadlinePassed($invoice)) {
                $this->expireAdjustmentLocked($invoice);

                return false;
            }
            $quote = $this->pricing->quote($invoice->subtotal,
                $invoice->pricing_region ?? $this->defaultRegion(), $invoice->coupon_code);
            $attempts = $invoice->pricing_recovery_attempts + 1;
            if ($quote->isDegraded() || ! $quote->subtotal->equals($invoice->subtotal) || ! $quote->reconciles()) {
                $invoice->update(['pricing_recovery_attempts' => $attempts,
                    'pricing_retry_at' => $this->clock->now()->addSeconds(min(3600, 60 * (2 ** min(6, $attempts - 1))))]);

                return false;
            }
            DB::transaction(function () use ($invoice, $quote, $attempts): void {
                $invoice->update(['discount' => $quote->discount, 'tax' => $quote->tax, 'total' => $quote->total,
                    'pricing_degraded' => false, 'pricing_recovery_attempts' => $attempts,
                    'pricing_retry_at' => null, 'repriced_at' => $this->clock->now(),
                    'next_attempt_at' => $invoice->due_at->max($this->clock->now())]);
            });

            return true;
        });
    }

    public function confirmCheckout(PaymentCheckout $checkout, VerifiedPayment $payment): bool
    {
        $invoice = Invoice::query()->findOrFail($checkout->invoice_id);
        try {
            return $this->withLock($invoice, fn (): bool => DB::transaction(function () use ($invoice, $checkout, $payment): bool {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $checkout = PaymentCheckout::query()->lockForUpdate()->findOrFail($checkout->id);
                if ($this->adjustmentDeadlinePassed($invoice)) {
                    $this->expireAdjustmentLocked($invoice);

                    return false;
                }
                if ($payment->reference->orderReference !== $checkout->order_reference
                    || $payment->reference->merchantHandle !== $checkout->merchant_handle
                    || $invoice->total->currency !== Currency::BRL || $checkout->currency !== Currency::BRL->value
                    || $payment->amount !== $checkout->amount_cents || $payment->amount !== $invoice->total->minorUnits
                    || $payment->paidAmount < $payment->amount || $payment->installments < 1 || $payment->installments > 12
                    || ! in_array($payment->captureMethod, ['pix', 'credit_card'], true)
                    || ($payment->captureMethod === 'pix' && $payment->installments !== 1)
                    || $invoice->pricing_degraded) {
                    return false;
                }
                if ($invoice->status === InvoiceStatus::Paid) {
                    return $checkout->transaction_nsu === $payment->reference->transactionNsu;
                }
                if ($invoice->status !== InvoiceStatus::Open) {
                    return false;
                }
                $checkout->update([
                    'status' => CheckoutStatus::Paid, 'transaction_nsu' => $payment->reference->transactionNsu,
                    'paid_amount_cents' => $payment->paidAmount, 'installments' => $payment->installments,
                    'capture_method' => $payment->captureMethod, 'verified_at' => $this->clock->now(),
                ]);
                $attempt = $this->recordAttempt($invoice, ChargeResult::succeeded($payment->reference->transactionNsu, 'infinitepay_verified'));
                $this->markPaid($invoice, $attempt);

                return true;
            }));
        } catch (UniqueConstraintViolationException $exception) {
            if (PaymentCheckout::query()->where('transaction_nsu', $payment->reference->transactionNsu)->whereKeyNot($checkout->id)->exists()) {
                return false;
            }
            throw $exception;
        }
    }

    public function issueAdjustment(SubscriptionPlanChanged $event): Invoice
    {
        $existing = Invoice::query()->where('subscription_change_id', $event->changeId)->first();
        if ($existing !== null) {
            return $existing;
        }
        $quote = $event->quote ?? throw new LogicException('An adjustment requires priced terms.');
        $ttl = config('infinitepay.checkout_ttl_seconds');
        $deadline = $this->clock->now()->addSeconds(is_int($ttl) ? max(60, min(31536000, $ttl)) : 604800)
            ->min($event->subscription->current_period_end);
        $invoice = Invoice::query()->create([
            'customer_id' => $event->subscription->customer_id, 'subscription_id' => $event->subscription->id,
            'subscription_change_id' => $event->changeId, 'kind' => InvoiceKind::Adjustment, 'status' => InvoiceStatus::Open,
            'subtotal' => $quote->subtotal, 'discount' => $quote->discount, 'tax' => $quote->tax, 'total' => $quote->total,
            'pricing_degraded' => $quote->isDegraded(), 'pricing_region' => $event->pricingRegion,
            'coupon_code' => $event->couponCode, 'period_start' => $event->subscription->current_period_start,
            'period_end' => $event->subscription->current_period_end, 'due_at' => $this->clock->now(),
            'next_attempt_at' => $this->clock->now(), 'payment_deadline_at' => $deadline, 'attempt_count' => 0,
        ]);
        InvoiceIssued::dispatch($invoice);

        return $invoice;
    }

    public function expireAdjustment(Invoice $invoice): Invoice
    {
        return $this->withLock($invoice, function () use ($invoice): Invoice {
            $invoice->refresh();

            return $this->adjustmentDeadlinePassed($invoice) ? $this->expireAdjustmentLocked($invoice) : $invoice;
        });
    }

    private function adjustmentDeadlinePassed(Invoice $invoice): bool
    {
        return $invoice->kind === InvoiceKind::Adjustment && $invoice->status === InvoiceStatus::Open
            && $invoice->payment_deadline_at !== null && $invoice->payment_deadline_at <= $this->clock->now();
    }

    private function expireAdjustmentLocked(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->update(['status' => InvoiceStatus::Void, 'next_attempt_at' => null]);
            $this->revertAdjustment($invoice);

            return $invoice->refresh();
        });
    }

    private function revertAdjustment(Invoice $invoice): void
    {
        if ($invoice->kind === InvoiceKind::Adjustment && $invoice->subscription_change_id !== null) {
            $this->subscriptions->revertPlanChange(SubscriptionChange::query()->findOrFail($invoice->subscription_change_id));
        }
    }

    /**
     * @throws InvoiceNotChargeableException
     */
    private function assertChargeable(Invoice $invoice): void
    {
        if (! $invoice->status->isChargeable()) {
            throw InvoiceNotChargeableException::alreadySettled($invoice->status->value);
        }

        if ($invoice->pricing_degraded) {
            throw InvoiceNotChargeableException::pricingDegraded();
        }

        if ($invoice->due_at > $this->clock->now()
            || ($invoice->next_attempt_at !== null && $invoice->next_attempt_at > $this->clock->now())) {
            throw InvoiceNotChargeableException::notDue();
        }
    }

    /**
     * Record settlement consistently, whether money arrived at the gateway or
     * the invoice has no amount to collect. Neither path remains in dunning.
     */
    private function settle(Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($invoice): void {
            $this->transitionTo($invoice, InvoiceStatus::Paid);
            $invoice->paid_at = $this->clock->now();
            $invoice->next_attempt_at = null;
            $invoice->save();
        });

        $invoice->refresh();

        $subscription = $invoice->subscription;

        if ($invoice->kind === InvoiceKind::Adjustment && $invoice->subscription_change_id !== null) {
            SubscriptionChange::query()->whereKey($invoice->subscription_change_id)->where('payment_status', 'pending')
                ->update(['payment_status' => 'paid']);
        }

        if ($subscription !== null && $invoice->kind !== InvoiceKind::Adjustment
            && ($subscription->status === SubscriptionStatus::PastDue
                || ($invoice->kind === InvoiceKind::Initial && $subscription->status === SubscriptionStatus::PendingPayment))) {
            $this->subscriptions->markActive($subscription);
        }

        return $invoice;
    }

    private function recordAttempt(Invoice $invoice, ChargeResult $result): ChargeAttempt
    {
        return DB::transaction(function () use ($invoice, $result): ChargeAttempt {
            $invoice->increment('attempt_count');

            return $invoice->attempts()->create([
                'succeeded' => $result->succeeded,
                'failure_reason' => $result->failureReason,
                'gateway_code' => $result->gatewayCode,
                'gateway_message' => $result->gatewayMessage,
                'gateway_reference' => $result->gatewayReference,
                'amount' => $invoice->total,
                'attempted_at' => $this->clock->now(),
            ]);
        });
    }

    private function markPaid(Invoice $invoice, ChargeAttempt $attempt): Invoice
    {
        $invoice = $this->settle($invoice);

        InvoicePaid::dispatch($invoice, $attempt);

        return $invoice;
    }

    /**
     * Record the failure, decide whether to keep chasing, and say so.
     */
    private function handleFailure(
        Invoice $invoice,
        ChargeAttempt $attempt,
        ChargeResult $result,
    ): Invoice {
        $invoice->refresh();

        $willRetry = $result->isWorthRetrying()
            && $invoice->attempt_count < $this->maxAttempts();

        // The subscription enters dunning on the first failure, not the last.
        // Past due still grants access — see SubscriptionStatus::grantsAccess()
        // — so this is a bookkeeping change, not a punishment.
        $subscription = $invoice->subscription;

        if ($invoice->kind !== InvoiceKind::Adjustment && $subscription !== null && $subscription->status->canTransitionTo(SubscriptionStatus::PastDue)) {
            $this->subscriptions->markPastDue($subscription);
        }

        DB::transaction(function () use ($invoice, $willRetry): void {
            if ($willRetry) {
                // The one place the retry schedule is read. The sweep only
                // ever reads the answer.
                $invoice->next_attempt_at = $this->clock->now()
                    ->addDays($this->daysUntilNextAttempt($invoice->attempt_count));
            } else {
                $this->transitionTo($invoice, InvoiceStatus::Uncollectible);
                $invoice->next_attempt_at = null;
            }

            $invoice->save();
        });

        $invoice->refresh();

        if (! $willRetry) {
            $this->revertAdjustment($invoice);
        }

        PaymentFailed::dispatch(
            $invoice,
            $attempt,
            $result->failureReason ?? throw new LogicException('A failed charge must carry a reason.'),
            $invoice->attempt_count,
            $willRetry,
        );

        if (! $willRetry) {
            InvoiceUncollectible::dispatch($invoice, $invoice->attempt_count);
        }

        return $invoice;
    }

    /**
     * The single door every invoice status change goes through, mirroring
     * {@see SubscriptionService::transitionTo()}.
     */
    private function transitionTo(Invoice $invoice, InvoiceStatus $target): void
    {
        if (! $invoice->status->canTransitionTo($target)) {
            throw InvoiceNotChargeableException::alreadySettled($invoice->status->value);
        }

        $invoice->status = $target;
    }

    private function findInvoiceForPeriod(Subscription $subscription, RenewalPeriod $period): ?Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', '!=', InvoiceKind::Adjustment)
            ->where('period_start', $period->start)
            ->first();
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withLock(Invoice $invoice, Closure $callback): mixed
    {
        return Cache::lock($invoice->lockKey(), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, $callback);
    }

    private function maxAttempts(): int
    {
        return count($this->retrySchedule());
    }

    /**
     * How long to wait before the nth retry.
     *
     * `$attemptsSoFar` is 1 after the first failure, so it indexes the
     * schedule directly: `[1, 3, 5, 7]` means wait a day, then three, then
     * five, then seven. Falls back to the last entry if the schedule is ever
     * shorter than the attempt count — a config change mid-dunning must not
     * produce an undefined index on a live invoice.
     */
    private function daysUntilNextAttempt(int $attemptsSoFar): int
    {
        $schedule = $this->retrySchedule();

        if ($schedule === []) {
            return 0;
        }

        return $schedule[$attemptsSoFar - 1] ?? $schedule[array_key_last($schedule)];
    }

    /**
     * @return list<int>
     */
    private function retrySchedule(): array
    {
        $schedule = config('billing.dunning.retry_days');

        if (! is_array($schedule)) {
            return [];
        }

        return array_values(array_map(intval(...), array_filter($schedule, is_numeric(...))));
    }

    private function defaultRegion(): string
    {
        $region = config('pricing.default_region');

        return is_string($region) ? $region : '';
    }
}
