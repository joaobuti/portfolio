<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\PlanChangeType;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SubscriptionPlanChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly Plan $fromPlan,
        public readonly Plan $toPlan,
        public readonly PlanChangeType $type,
        public readonly ProrationResult $proration,
    ) {}
}
