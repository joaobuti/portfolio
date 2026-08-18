<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

use App\Support\Money;

/**
 * The full breakdown of a mid-cycle plan change.
 *
 * We return the workings, not just the total, because "why was I charged
 * R$ 43,33?" is the single most common billing support ticket. Everything the
 * support agent needs to answer it is in this object, and the API exposes it
 * as a preview before the customer commits.
 */
final readonly class ProrationResult
{
    public function __construct(
        public int $daysInPeriod,
        public int $daysRemaining,
        /** Unused portion of what the customer already paid for. */
        public Money $creditFromCurrentPlan,
        /** Cost of the new plan for the remainder of the current period. */
        public Money $chargeForNewPlan,
        /** Positive: charge the customer now. Negative: credit their balance. */
        public Money $amountDue,
    ) {}

    public static function none(Money $zero): self
    {
        return new self(0, 0, $zero, $zero, $zero);
    }

    public function isChargeable(): bool
    {
        return $this->amountDue->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->amountDue->isNegative();
    }

    /**
     * Fraction of the period still unused, as a percentage rounded to one
     * decimal — for display only, never for calculation.
     */
    public function remainingPercentage(): float
    {
        if ($this->daysInPeriod === 0) {
            return 0.0;
        }

        return round($this->daysRemaining / $this->daysInPeriod * 100, 1);
    }
}
