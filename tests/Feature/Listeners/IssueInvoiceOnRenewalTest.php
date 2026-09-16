<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Events\Subscriptions\SubscriptionRenewed;
use App\Jobs\Billing\CollectInvoice;
use App\Listeners\Billing\IssueInvoiceOnRenewal;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * The seam that turns "the period advanced" into "money is owed".
 *
 * The listener is invoked directly rather than through the event bus: what is
 * under test is its behaviour, and dispatching the event would also drag in
 * the activity log listener and prove nothing extra.
 */
beforeEach(function (): void {
    freezeClockAt('2026-07-01 00:05:00');

    $this->customer = Customer::factory()->create();
    $this->subscription = Subscription::factory()
        ->for($this->customer)
        ->for(Plan::factory()->priced('100.00')->create(), 'plan')
        ->period(
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-08-01'),
        )
        ->create();
});

it('issues an invoice and queues its collection', function (): void {
    config()->set('pricing.enabled', true);
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response([
        'currency' => 'BRL',
        'subtotalMinorUnits' => 10_000,
        'discountMinorUnits' => 0,
        'taxableMinorUnits' => 10_000,
        'taxMinorUnits' => 900,
        'totalMinorUnits' => 10_900,
        'lines' => [],
        'coupon' => null,
        'taxRegion' => 'BR-SP-ISS',
    ])]);

    Queue::fake();

    app(IssueInvoiceOnRenewal::class)(
        new SubscriptionRenewed($this->subscription, CarbonImmutable::parse('2026-07-01')),
    );

    $invoice = Invoice::query()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->tax->minorUnits)->toBe(900)
        ->and($invoice->total->minorUnits)->toBe(10_900)
        ->and($invoice->pricing_degraded)->toBeFalse();

    Queue::assertPushed(
        CollectInvoice::class,
        fn (CollectInvoice $job): bool => $job->invoiceUuid === $invoice->uuid,
    );
});

it('issues but does not queue collection when pricing is degraded', function (): void {
    // A job guaranteed to throw would just fill the failed jobs table. The
    // invoice still exists, which is the recoverable state.
    config()->set('pricing.enabled', false);
    Queue::fake();

    app(IssueInvoiceOnRenewal::class)(
        new SubscriptionRenewed($this->subscription, CarbonImmutable::parse('2026-07-01')),
    );

    expect(Invoice::query()->firstOrFail()->pricing_degraded)->toBeTrue();

    Queue::assertNotPushed(CollectInvoice::class);
});

it('does not double-invoice when the listener runs twice', function (): void {
    // Queued listeners can be retried. The unique index is what makes that
    // safe, and this proves the service leans on it rather than throwing.
    config()->set('pricing.enabled', false);
    Queue::fake();

    $event = new SubscriptionRenewed($this->subscription, CarbonImmutable::parse('2026-07-01'));

    app(IssueInvoiceOnRenewal::class)($event);
    app(IssueInvoiceOnRenewal::class)($event);

    expect(Invoice::query()->count())->toBe(1);
});

it('runs only after the renewal transaction commits', function (): void {
    // Without afterCommit a worker can read the old period and invoice a month
    // that was already billed.
    expect((new ReflectionClass(IssueInvoiceOnRenewal::class))
        ->getDefaultProperties()['afterCommit'])->toBeTrue();
});
