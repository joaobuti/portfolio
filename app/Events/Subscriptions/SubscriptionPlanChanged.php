<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\DTOs\Pricing\PriceQuote;
use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\PlanChangeType;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class SubscriptionPlanChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public Plan $fromPlan,
        public Plan $toPlan,
        public PlanChangeType $type,
        public ProrationResult $proration,
        public ?int $changeId = null,
        public ?PriceQuote $quote = null,
        public ?string $couponCode = null,
        public ?string $pricingRegion = null,
    ) {}
}
