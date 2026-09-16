<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a user is, in terms of whose data they may act on.
 *
 * The enum owns the rule, exactly as {@see SubscriptionStatus} owns the
 * transition table. Policies ask it a question; they never compare role
 * strings by hand. Adding a role means adding a case and answering the
 * questions below — no policy needs editing, which is the open/closed
 * principle doing actual work rather than decorating a diagram.
 */
enum UserRole: string
{
    /** Acts only on the customer they belong to. The self-service case. */
    case Customer = 'customer';

    /** Acts on any customer. Support and back-office. */
    case Staff = 'staff';

    /**
     * Whether this role is unscoped — able to act on every customer.
     *
     * Expressed as a capability rather than as `=== self::Staff` at each call
     * site, so a future `Auditor` role that also sees everything is one case
     * here instead of a hunt through the policies.
     */
    public function accessesAnyCustomer(): bool
    {
        return match ($this) {
            self::Staff => true,
            self::Customer => false,
        };
    }

    /**
     * Whether a user of this role must be bound to a customer record.
     *
     * Enforced at the model boundary: a customer-role user with no customer
     * would be an account that can authenticate and reach nothing, which is a
     * data bug that should never reach the database.
     */
    public function requiresCustomer(): bool
    {
        return ! $this->accessesAnyCustomer();
    }

    /**
     * The abilities a freshly issued token gets when the caller does not ask
     * for a narrower set.
     *
     * Least privilege by default: a customer cannot mint themselves a token
     * that administers anything, because the ceiling is defined here and not
     * by whatever the client happened to send.
     *
     * @return list<TokenAbility>
     */
    public function defaultAbilities(): array
    {
        return match ($this) {
            self::Staff => TokenAbility::cases(),
            self::Customer => [
                TokenAbility::PlansRead,
                TokenAbility::SubscriptionsRead,
                TokenAbility::SubscriptionsWrite,
            ],
        };
    }
}
