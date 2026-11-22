<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a subscription, plus the transitions the business allows.
 *
 * Keeping the transition table on the enum means there is exactly one place to
 * read to know what can happen next — and exactly one place to change when the
 * business changes its mind. Services ask this enum for permission; they never
 * hard-code a status comparison.
 */
enum SubscriptionStatus: string
{
    case PendingPayment = 'pending_payment';

    /** Inside the free trial window. Access granted, nothing charged yet. */
    case Trialing = 'trialing';

    /** Paid and current. */
    case Active = 'active';

    /** A charge failed and we are in the dunning window. Access still granted. */
    case PastDue = 'past_due';

    /** Ended by the customer or by us. */
    case Canceled = 'canceled';

    /** Ran to the end of its paid period and was not renewed. */
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Active, self::Canceled, self::Expired],
            self::Trialing => [self::PendingPayment, self::Active, self::PastDue, self::Canceled, self::Expired],
            self::Active => [self::PastDue, self::Canceled, self::Expired],
            self::PastDue => [self::Active, self::Canceled, self::Expired],
            self::Canceled => [self::Expired],
            self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether the customer should still be able to use the product.
     *
     * Note that past due grants access on purpose: cutting a paying customer
     * off on the first failed charge is how you turn a card expiry into churn.
     */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], strict: true);
    }

    /**
     * Whether the renewal cycle should keep running for this subscription.
     */
    public function isRenewable(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], strict: true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Aguardando pagamento',
            self::Trialing => 'Em teste',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento pendente',
            self::Canceled => 'Cancelada',
            self::Expired => 'Expirada',
        };
    }
}
