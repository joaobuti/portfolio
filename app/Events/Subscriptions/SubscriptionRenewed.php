<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\CarbonImmutable;

final class SubscriptionRenewed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly CarbonImmutable $previousPeriodEnd,
    ) {}
}
