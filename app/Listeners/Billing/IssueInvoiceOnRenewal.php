<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Subscriptions\SubscriptionRenewed;
use App\Jobs\Billing\CollectInvoice;
use App\Services\Billing\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Turns a renewal into money owed.
 *
 * ## Why a listener and not a call inside SubscriptionService::renew()
 *
 * Renewing is about what a subscription *is*; invoicing is about what it
 * *costs*. Wiring them through an event keeps the dependency pointing one way
 * — billing knows about subscriptions, subscriptions know nothing about
 * billing — which is what lets the renewal tests stay free of a payment
 * gateway.
 *
 * ## Why `$afterCommit` is not optional here
 *
 * Without it a worker can pick this up before the transaction that advanced
 * the period has committed, read the *old* `current_period_start`, and issue
 * an invoice for a period that was already billed. The unique index would
 * catch it, but relying on a constraint to paper over a race is how you end up
 * with a table of swallowed exceptions.
 *
 * Issuing and collecting are separate steps on purpose: an invoice that exists
 * but has not been charged is a recoverable state, and one the dunning sweep
 * can pick up. An invoice that was never created is not.
 */
final class IssueInvoiceOnRenewal implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public function __construct(private readonly BillingService $billing) {}

    public function __invoke(SubscriptionRenewed $event): void
    {
        $invoice = $this->billing->issueForPeriod($event->subscription);

        if (! $invoice->isChargeable()) {
            // A degraded invoice waits for a human or for the pricing service
            // to come back. Dispatching a collection job that is guaranteed to
            // throw would just fill the failed jobs table.
            return;
        }

        CollectInvoice::dispatch($invoice->uuid);
    }

    public function viaQueue(): string
    {
        $queue = config('billing.queue');

        return is_string($queue) ? $queue : 'billing';
    }
}
