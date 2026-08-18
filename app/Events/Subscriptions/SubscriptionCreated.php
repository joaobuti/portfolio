<?php

declare(strict_types=1);

namespace App\Events\Subscriptions;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a subscription is persisted, inside the same transaction's
 * afterCommit window.
 *
 * Events are how this module stays uncoupled from the ones that come after it.
 * Billing and Notifications will listen; Subscriptions will never know they
 * exist.
 */
final class SubscriptionCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Subscription $subscription) {}
}
