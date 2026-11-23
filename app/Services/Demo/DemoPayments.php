<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use App\Services\Billing\CheckoutService;
use App\Services\Pricing\PricingClient;
use App\Services\Subscriptions\BillingCalendar;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Clock;
use Illuminate\Support\Facades\Cache;

final readonly class DemoPayments
{
    public function __construct(private DemoConfiguration $configuration, private Clock $clock,
        private PricingClient $pricing, private SubscriptionService $subscriptions,
        private CheckoutService $checkouts, private BillingCalendar $calendar) {}

    public function simulate(Invoice $invoice, string $outcome): Invoice
    {
        $this->configuration->ensureEnabled();
        abort_unless($invoice->customer()->whereNotNull('demo_workspace_id')->exists(), 403);
        if ($outcome === 'pending') {
            /** @var Invoice $pending */
            $pending = Cache::lock($invoice->lockKey(), 30)->block(10, function () use ($invoice): Invoice {
                $invoice->refresh();
                if ($invoice->status === InvoiceStatus::Paid) {
                    return $invoice;
                }
                abort_unless($invoice->isChargeable() && $invoice->due_at <= $this->clock->now()
                    && ($invoice->next_attempt_at === null || $invoice->next_attempt_at <= $this->clock->now())
                    && ($invoice->payment_deadline_at === null || $invoice->payment_deadline_at > $this->clock->now()), 409);
                $invoice->forceFill(['demo_payment_status' => 'pending'])->save();

                return $invoice;
            });

            return $pending;
        }
        if ($invoice->status !== InvoiceStatus::Paid) {
            $billing = new BillingService(new SimulationGateway($outcome === 'approved'), $this->pricing,
                $this->subscriptions, $this->clock, $this->checkouts, $this->calendar);
            $invoice = $billing->collect($invoice);
        }
        $invoice->forceFill(['demo_payment_status' => match ($invoice->status) {
            InvoiceStatus::Paid => 'approved',
            InvoiceStatus::Open, InvoiceStatus::Uncollectible => 'declined',
            default => null,
        }])->save();

        return $invoice;
    }
}
