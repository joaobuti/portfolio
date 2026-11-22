<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Enums\PlanChangeType;
use App\Events\Subscriptions\SubscriptionPlanChanged;
use App\Services\Billing\BillingService;

final readonly class IssueAdjustmentInvoice
{
    public function __construct(private BillingService $billing) {}

    public function __invoke(SubscriptionPlanChanged $event): void
    {
        if ($event->type === PlanChangeType::Upgrade && $event->changeId !== null && $event->quote instanceof \App\DTOs\Pricing\PriceQuote) {
            $this->billing->issueAdjustment($event);
        }
    }
}
