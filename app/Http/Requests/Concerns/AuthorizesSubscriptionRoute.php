<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Subscription;

/**
 * Authorises a request against the `{subscription}` bound to its route.
 *
 * A trait rather than a shared base class: the requests are `final` by the
 * architecture test, and an abstract parent would be the one class in the
 * application that is not — buying inheritance we do not need in exchange for
 * an exception to a rule that is otherwise absolute.
 *
 * What it removes is the copy of these five lines that would otherwise sit in
 * every request touching a subscription, drifting apart the first time one of
 * them is changed and the others are missed.
 */
trait AuthorizesSubscriptionRoute
{
    /**
     * @param  string  $ability  A method on {@see \App\Policies\SubscriptionPolicy}.
     */
    protected function authorizeSubscription(string $ability): bool
    {
        $subscription = $this->route('subscription');

        // Not a Subscription means the binding did not resolve — a bad uuid.
        // Refusing here yields 403 before Laravel's 404, which is the right
        // order: an unauthenticated caller learns nothing about which
        // subscription ids exist.
        if (! $subscription instanceof Subscription) {
            return false;
        }

        return $this->user()?->can($ability, $subscription) === true;
    }
}
