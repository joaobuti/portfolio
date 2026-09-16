<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\CollectInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 04:00:00');
    Queue::fake();

    $this->customer = Customer::factory()->create();
});

function invoiceDueAt(Customer $customer, ?string $nextAttempt, array $overrides = []): Invoice
{
    return Invoice::factory()->for($customer)->create([
        'next_attempt_at' => $nextAttempt === null ? null : CarbonImmutable::parse($nextAttempt),
        ...$overrides,
    ]);
}

describe('what the sweep picks up', function (): void {
    it('queues an invoice whose next attempt has come due', function (): void {
        $invoice = invoiceDueAt($this->customer, '2026-06-16 03:00:00');

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertPushed(
            CollectInvoice::class,
            fn (CollectInvoice $job): bool => $job->invoiceUuid === $invoice->uuid,
        );
    });

    it('leaves an invoice whose next attempt is still in the future', function (): void {
        invoiceDueAt($this->customer, '2026-06-18 03:00:00');

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('never re-queues a settled invoice', function (): void {
        // Paid invoices carry a null next_attempt_at, and the scope requires
        // one — two independent reasons this can never be swept up. Charging
        // a paid invoice is the most expensive bug this module can have.
        invoiceDueAt($this->customer, null, ['status' => InvoiceStatus::Paid]);
        invoiceDueAt($this->customer, '2026-06-15 03:00:00', ['status' => InvoiceStatus::Paid]);

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('never queues a written-off invoice', function (): void {
        invoiceDueAt($this->customer, '2026-06-15 03:00:00', [
            'status' => InvoiceStatus::Uncollectible,
        ]);

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('never queues an invoice priced without tax', function (): void {
        // A job guaranteed to throw would just fill the failed jobs table.
        invoiceDueAt($this->customer, '2026-06-15 03:00:00', ['pricing_degraded' => true]);

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('ignores an invoice with no next attempt scheduled', function (): void {
        invoiceDueAt($this->customer, null);

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertNothingPushed();
    });
});

describe('operator controls', function (): void {
    it('queues nothing in dry-run mode', function (): void {
        invoiceDueAt($this->customer, '2026-06-15 03:00:00');

        $this->artisan('invoices:collect', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('can replay a past dunning day with --as-of', function (): void {
        // Due tomorrow, so today's sweep skips it — but a sweep run as if it
        // were the day after picks it up.
        invoiceDueAt($this->customer, '2026-06-17 03:00:00');

        $this->artisan('invoices:collect')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('invoices:collect', ['--as-of' => '2026-06-18T00:00:00Z'])->assertSuccessful();
        Queue::assertPushed(CollectInvoice::class);
    });

    it('queues one job per invoice', function (): void {
        invoiceDueAt($this->customer, '2026-06-15 03:00:00');
        invoiceDueAt($this->customer, '2026-06-15 03:00:00', ['period_start' => CarbonImmutable::parse('2026-05-01')]);

        $this->artisan('invoices:collect')->assertSuccessful();

        Queue::assertPushed(CollectInvoice::class, 2);
    });
});
