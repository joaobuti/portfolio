<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SubscriptionCanceled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        /** False means access continues until the end of the paid period. */
        public readonly bool $immediately,
    ) {}
}
