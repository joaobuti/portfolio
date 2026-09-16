<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoiceIssued;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\InvoiceUncollectible;
use App\Events\Billing\PaymentFailed;
use App\Exceptions\Billing\InvoiceNotChargeableException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    // Pricing off, so every quote is degraded and the tests that care about
    // tax say so explicitly. See the pricing block below.
    config()->set('pricing.enabled', false);

    $this->service = app(BillingService::class);
    $this->customer = Customer::factory()->create();
});

function subscriptionOn(Customer $customer, string $amount = '100.00'): Subscription
{
    return Subscription::factory()
        ->for($customer)
        ->for(Plan::factory()->priced($amount)->create(), 'plan')
        ->period(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-07-01'),
        )
        ->create();
}

describe('issuing', function (): void {
    it('issues an invoice for the current period', function (): void {
        Event::fake([InvoiceIssued::class]);

        $invoice = $this->service->issueForPeriod(subscriptionOn($this->customer));

        expect($invoice->status)->toBe(InvoiceStatus::Open)
            ->and($invoice->total->minorUnits)->toBe(10_000)
            ->and($invoice->period_start?->toDateString())->toBe('2026-06-01');

        Event::assertDispatched(InvoiceIssued::class);
    });

    it('is idempotent: a second call returns the same invoice', function (): void {
        // The guard against a retried renewal job billing the same month
        // twice. It must hold without the caller doing anything.
        $subscription = subscriptionOn($this->customer);

        $first = $this->service->issueForPeriod($subscription);
        $second = $this->service->issueForPeriod($subscription);

        expect($second->id)->toBe($first->id)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('refuses a duplicate at the database level, not just in PHP', function (): void {
        // Proves the unique index exists. A check in application code would
        // not survive two workers racing.
        $subscription = subscriptionOn($this->customer);
        $this->service->issueForPeriod($subscription);

        Invoice::query()->create([
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'status' => InvoiceStatus::Open,
            'subtotal' => $subscription->plan->amount,
            'discount' => App\Support\Money::zero($subscription->plan->amount->currency),
            'tax' => App\Support\Money::zero($subscription->plan->amount->currency),
            'total' => $subscription->plan->amount,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'due_at' => now(),
        ]);
    })->throws(Illuminate\Database\UniqueConstraintViolationException::class);
});

describe('collecting', function (): void {
    it('marks an invoice paid when the gateway accepts', function (): void {
        Event::fake([InvoicePaid::class]);

        $invoice = Invoice::factory()->for($this->customer)->create();

        $collected = $this->service->collect($invoice);

        expect($collected->status)->toBe(InvoiceStatus::Paid)
            ->and($collected->paid_at?->toDateTimeString())->toBe('2026-06-16 09:00:00')
            ->and($collected->attempts()->count())->toBe(1)
            ->and($collected->attempts()->first()?->succeeded)->toBeTrue();

        Event::assertDispatched(InvoicePaid::class);
    });

    it('settles a zero-value invoice without touching the gateway', function (): void {
        // A zero-value charge is a fee for nothing, and some gateways reject
        // it — which would look like a failure and start dunning a customer
        // who owes nothing.
        $invoice = Invoice::factory()->for($this->customer)->totalling(0)->create();

        $collected = $this->service->collect($invoice);

        expect($collected->status)->toBe(InvoiceStatus::Paid)
            ->and($collected->attempts()->count())->toBe(0);
    });

    it('refuses to charge an already-paid invoice', function (): void {
        $invoice = Invoice::factory()->for($this->customer)->paid()->create();

        $this->service->collect($invoice);
    })->throws(InvoiceNotChargeableException::class);

    it('refuses to charge an invoice priced without tax', function (): void {
        // ADR-0005: billing an untaxed amount is a compliance problem that
        // surfaces months later.
        $invoice = Invoice::factory()->for($this->customer)->degraded()->create();

        $this->service->collect($invoice);
    })->throws(InvoiceNotChargeableException::class);
});

describe('failure and dunning', function (): void {
    it('records the attempt and emits PaymentFailed', function (): void {
        Event::fake([PaymentFailed::class]);

        $invoice = Invoice::factory()->for($this->customer)->decliningAmount()->create();

        $collected = $this->service->collect($invoice);

        expect($collected->status)->toBe(InvoiceStatus::Open)
            ->and($collected->attempt_count)->toBe(1);

        $attempt = $collected->attempts()->first();

        expect($attempt?->succeeded)->toBeFalse()
            ->and($attempt?->failure_reason)->toBe(PaymentFailureReason::Declined)
            ->and($attempt?->gateway_message)->not->toBeNull();

        Event::assertDispatched(
            PaymentFailed::class,
            fn (PaymentFailed $event): bool => $event->reason === PaymentFailureReason::Declined
                && $event->attemptNumber === 1
                && $event->willRetry === true,
        );
    });

    it('puts the subscription into dunning on the first failure', function (): void {
        // Past due still grants access — cutting a paying customer off on the
        // first failed charge is how a card expiry becomes churn.
        $subscription = subscriptionOn($this->customer);
        $invoice = Invoice::factory()
            ->for($this->customer)
            ->for($subscription)
            ->decliningAmount()
            ->create();

        $this->service->collect($invoice);

        expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::PastDue)
            ->and(SubscriptionStatus::PastDue->grantsAccess())->toBeTrue();
    });

    it('gives up immediately on a failure not worth retrying', function (): void {
        Event::fake([PaymentFailed::class, InvoiceUncollectible::class]);

        // An expired card retried eight times is eight guaranteed failures.
        $invoice = Invoice::factory()->for($this->customer)->expiredCardAmount()->create();

        $collected = $this->service->collect($invoice);

        expect($collected->status)->toBe(InvoiceStatus::Uncollectible);

        Event::assertDispatched(
            PaymentFailed::class,
            fn (PaymentFailed $event): bool => $event->willRetry === false,
        );
        Event::assertDispatched(InvoiceUncollectible::class);
    });

    it('writes the invoice off once the schedule is exhausted', function (): void {
        config()->set('billing.dunning.retry_days', [1, 3]);

        $invoice = Invoice::factory()->for($this->customer)->decliningAmount()->create();

        $this->service->collect($invoice); // attempt 1, will retry
        expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Open);

        $this->service->collect($invoice->fresh()); // attempt 2, limit reached
        expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Uncollectible)
            ->and($invoice->fresh()?->attempt_count)->toBe(2);
    });

    it('brings a past-due subscription back to active when payment succeeds', function (): void {
        $subscription = subscriptionOn($this->customer);

        // Fail once to enter dunning.
        $failing = Invoice::factory()->for($this->customer)->for($subscription)->decliningAmount()->create();
        $this->service->collect($failing);
        expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::PastDue);

        // Then succeed on the next invoice.
        $paying = Invoice::factory()->for($this->customer)->for($subscription)->create([
            'period_start' => CarbonImmutable::parse('2026-07-01'),
        ]);
        $this->service->collect($paying);

        expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active);
    });
});

describe('the retry schedule', function (): void {
    it('schedules the next attempt from the configured schedule', function (): void {
        config()->set('billing.dunning.retry_days', [2, 5]);

        $invoice = Invoice::factory()->for($this->customer)->decliningAmount()->create();

        $this->service->collect($invoice);

        // First failure indexes the schedule at 0: two days.
        expect($invoice->fresh()?->next_attempt_at?->toDateTimeString())
            ->toBe('2026-06-18 09:00:00');
    });

    it('walks the schedule across successive failures', function (): void {
        config()->set('billing.dunning.retry_days', [2, 5]);

        $invoice = Invoice::factory()->for($this->customer)->decliningAmount()->create();

        $this->service->collect($invoice);
        $this->service->collect($invoice->fresh());

        // Second failure is the last of the schedule, so it is written off and
        // must never be swept up again.
        expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Uncollectible)
            ->and($invoice->fresh()?->next_attempt_at)->toBeNull();
    });

    it('clears the next attempt when the invoice is paid', function (): void {
        $invoice = Invoice::factory()->for($this->customer)->create();

        $this->service->collect($invoice);

        expect($invoice->fresh()?->next_attempt_at)->toBeNull();
    });

    it('stops scheduling for a failure no retry can fix', function (): void {
        $invoice = Invoice::factory()->for($this->customer)->expiredCardAmount()->create();

        $this->service->collect($invoice);

        expect($invoice->fresh()?->next_attempt_at)->toBeNull()
            ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Uncollectible);
    });

    it('survives a schedule shortened mid-dunning', function (): void {
        // A config change must not produce an undefined index on a live
        // invoice that is already three attempts deep.
        config()->set('billing.dunning.retry_days', [1, 2, 3, 4]);
        $invoice = Invoice::factory()->for($this->customer)->decliningAmount()->create();
        $this->service->collect($invoice);
        $this->service->collect($invoice->fresh());

        config()->set('billing.dunning.retry_days', [1]);

        expect(fn () => $this->service->collect($invoice->fresh()))->not->toThrow(Throwable::class);
    });
});

describe('pricing integration', function (): void {
    it('marks the invoice degraded when pricing is unavailable', function (): void {
        // Pricing is disabled in beforeEach, so every quote comes back
        // degraded — and a degraded invoice must never be charged.
        $invoice = $this->service->issueForPeriod(subscriptionOn($this->customer));

        expect($invoice->pricing_degraded)->toBeTrue()
            ->and($invoice->isChargeable())->toBeFalse()
            ->and($invoice->tax->isZero())->toBeTrue();
    });
});
