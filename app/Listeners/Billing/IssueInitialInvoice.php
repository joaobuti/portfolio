<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Subscriptions\SubscriptionCreated;
use App\Services\Billing\BillingService;

/** Synchronous so pending subscription and initial invoice commit together. */
final readonly class IssueInitialInvoice
{
    public function __construct(private BillingService $billing) {}

    public function __invoke(SubscriptionCreated $event): void
    {
        if ($event->initialPeriod instanceof \App\DTOs\Subscriptions\RenewalPeriod) {
            $this->billing->issueForPeriod($event->subscription, $event->initialPeriod, true);
        }
    }
}
