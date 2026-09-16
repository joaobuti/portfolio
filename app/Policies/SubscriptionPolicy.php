<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;

/**
 * Who may act on which subscription.
 *
 * ## One question, asked four ways
 *
 * Every method here reduces to {@see User::canActOnCustomer()}. That is not
 * laziness — it is the point. Ownership has exactly one definition, so a role
 * added tomorrow changes {@see \App\Enums\UserRole} and nothing else. A policy
 * that re-derived "is this mine?" per action is a policy where three of the
 * four eventually disagree.
 *
 * ## What this class is NOT responsible for
 *
 * Token scope. Whether the caller's token carries `subscriptions:write` is
 * enforced by Sanctum's `abilities` middleware on the route. Two different
 * questions — "is this actor allowed near this data?" and "did this particular
 * credential grant that power?" — kept in two places, because folding them
 * together means every policy method needs a token in scope and cannot be
 * unit tested without one.
 */
final readonly class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return $this->owns($user, $subscription);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->owns($user, $subscription);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $this->owns($user, $subscription);
    }

    /**
     * Opening a subscription is authorised against the customer it would
     * belong to, since there is no subscription yet to own.
     */
    public function createFor(User $user, Customer $customer): bool
    {
        return $user->canActOnCustomer($customer);
    }

    private function owns(User $user, Subscription $subscription): bool
    {
        // The foreign key, not the relation. Reading `$subscription->customer`
        // would lazy load, and this application promotes lazy loading to an
        // exception via `Model::shouldBeStrict()` — so the obvious version of
        // this line would throw on every route-model-bound request. Comparing
        // the key is also simply correct: it is the same answer with no query.
        return $user->canActOnCustomerId($subscription->customer_id);
    }
}
