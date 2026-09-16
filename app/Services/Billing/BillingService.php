<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\ChargeResult;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoiceIssued;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceUncollectible;
use App\Events\Billing\PaymentFailed;
use App\Exceptions\Billing\InvoiceNotChargeableException;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Pricing\PricingClient;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Clock;
use App\Support\Money;
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
        private PaymentGateway $gateway,
        private PricingClient $pricing,
        private SubscriptionService $subscriptions,
        private Clock $clock,
    ) {}

    /**
     * Issue the invoice for a subscription's current period.
     *
     * Idempotent by database constraint: called twice for the same period, the
     * second call returns the first invoice rather than creating a second or
     * throwing. That is what makes it safe to call from a queue job that may
     * be retried.
     */
    public function issueForPeriod(Subscription $subscription): Invoice
    {
        $existing = $this->findInvoiceForPeriod($subscription);

        if ($existing instanceof Invoice) {
            return $existing;
        }

        $plan = $subscription->plan;
        $quote = $this->pricing->quote(
            amount: $plan->amount,
            region: $this->defaultRegion(),
        );

        try {
            $invoice = DB::transaction(fn (): Invoice => Invoice::query()->create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'status' => InvoiceStatus::Open,
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'tax' => $quote->tax,
                'total' => $quote->total,
                'pricing_degraded' => $quote->isDegraded(),
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
                'due_at' => $dueAt = $this->clock->now()->addDays($this->dueInDays()),
                'attempt_count' => 0,
                // First attempt is simply "when it falls due".
                'next_attempt_at' => $dueAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another worker issued it between our check and our insert. The
            // desired end state is "an invoice exists for this period", and it
            // does — so this is a success, not a failure.
            return $this->findInvoiceForPeriod($subscription)
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

            $this->assertChargeable($invoice);

            if ($invoice->isZeroValue()) {
                return $this->settleWithoutCharging($invoice);
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

            $attempt = $this->recordAttempt($invoice, $result);

            return $result->succeeded
                ? $this->markPaid($invoice, $attempt)
                : $this->handleFailure($invoice, $attempt, $result);
        });
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
    }

    /**
     * A fully discounted invoice is settled without touching the gateway.
     *
     * A zero-value charge is a fee for nothing, and some gateways reject it
     * outright — which would look like a payment failure and start dunning a
     * customer who owes us nothing.
     */
    private function settleWithoutCharging(Invoice $invoice): Invoice
    {
        DB::transaction(function () use ($invoice): void {
            $this->transitionTo($invoice, InvoiceStatus::Paid);
            $invoice->paid_at = $this->clock->now();
            $invoice->save();
        });

        return $invoice->refresh();
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
        DB::transaction(function () use ($invoice): void {
            $this->transitionTo($invoice, InvoiceStatus::Paid);
            $invoice->paid_at = $this->clock->now();
            // Settled: the sweep must never pick this up again.
            $invoice->next_attempt_at = null;
            $invoice->save();
        });

        $invoice->refresh();

        // A subscription that was in dunning is now current again. This is the
        // first caller markActive() has ever had.
        $subscription = $invoice->subscription;

        if ($subscription !== null && $subscription->status === SubscriptionStatus::PastDue) {
            $this->subscriptions->markActive($subscription);
        }

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

        if ($subscription !== null && $subscription->status !== SubscriptionStatus::PastDue) {
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

    private function findInvoiceForPeriod(Subscription $subscription): ?Invoice
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('period_start', $subscription->current_period_start)
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

    private function dueInDays(): int
    {
        $days = config('billing.dunning.due_in_days');

        return is_int($days) ? $days : 0;
    }

    private function defaultRegion(): string
    {
        $region = config('pricing.default_region');

        return is_string($region) ? $region : '';
    }
}
