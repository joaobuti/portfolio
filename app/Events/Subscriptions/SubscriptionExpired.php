<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class SubscriptionExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
