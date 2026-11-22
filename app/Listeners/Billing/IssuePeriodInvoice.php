<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Subscriptions\SubscriptionBillingPeriodStarted;
use App\Services\Billing\BillingService;

final readonly class IssuePeriodInvoice
{
    public function __construct(private BillingService $billing) {}

    public function __invoke(SubscriptionBillingPeriodStarted $event): void
    {
        $this->billing->issueForPeriod($event->subscription, $event->period, $event->firstPayment);
    }
}
