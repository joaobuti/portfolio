<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an invoice.
 *
 * Modelled on the same principle as {@see SubscriptionStatus}: the enum owns
 * the transition table, so there is one place to read to know what can happen
 * next and one place to change when the business changes its mind.
 *
 * There is no `Draft`. An invoice in this system is created already owing
 * money — it is issued by a renewal or a plan change, both of which know the
 * amount at the moment they fire. A draft state would be a status no code path
 * can produce.
 */
enum InvoiceStatus: string
{
    /** Issued and awaiting payment. */
    case Open = 'open';

    /** Settled. Terminal. */
    case Paid = 'paid';

    /**
     * Written off after dunning gave up. Terminal.
     *
     * Distinct from `Void`: uncollectible means we tried and failed, which is
     * a revenue fact the finance team reconciles. Void means the invoice
     * should never have existed.
     */
    case Uncollectible = 'uncollectible';

    /** Cancelled before payment — a mistake, or a subscription reversed. Terminal. */
    case Void = 'void';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::Paid, self::Uncollectible, self::Void],
            self::Paid, self::Uncollectible, self::Void => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether another charge may be attempted.
     *
     * The single question the dunning cycle asks. A paid or written-off
     * invoice must never be charged again — that is a duplicate charge, the
     * most expensive bug this module can have.
     */
    public function isChargeable(): bool
    {
        return $this === self::Open;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Paid => 'Paga',
            self::Uncollectible => 'Incobrável',
            self::Void => 'Cancelada',
        };
    }
}
