<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\DTOs\Subscriptions\RenewalPeriod;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class SubscriptionRenewed
{
    use Dispatchable;
    use SerializesModels;

    public RenewalPeriod $period;

    public function __construct(
        public Subscription $subscription,
        public CarbonImmutable $previousPeriodEnd,
        ?RenewalPeriod $period = null,
        public bool $firstPayment = false,
    ) {
        $this->period = $period ?? new RenewalPeriod(
            $subscription->current_period_start->toDateTimeImmutable(),
            $subscription->current_period_end->toDateTimeImmutable(),
            $subscription->plan->amount,
        );
    }
}
