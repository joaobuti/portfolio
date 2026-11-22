<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\DTOs\Subscriptions\RenewalPeriod;
use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Synchronous invoicing hook: the invoice commits with its service period. */
final readonly class SubscriptionBillingPeriodStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Subscription $subscription, public RenewalPeriod $period, public bool $firstPayment) {}
}
