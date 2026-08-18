<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\DTOs\Subscriptions\ProrationResult;
use App\Support\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Works out what a customer owes when they change plans mid-cycle.
 *
 * This class is the reason the rest of the module can stay simple. It has no
 * constructor dependencies, touches no database, and takes "now" as an
 * argument rather than reading the clock — so every edge case below is a
 * sub-millisecond unit test rather than a fixture-heavy integration test.
 *
 * ## The policy, stated once
 *
 * - Billing is proportional to **whole remaining days**. A customer who
 *   upgrades at 23:50 is charged for that day as if they had upgraded at
 *   00:00, which favours them. Sub-day proration is technically doable and
 *   commercially pointless.
 * - The unused portion of the current plan becomes a **credit**, always.
 *   We never keep money for service not rendered.
 * - The result may be **negative** (a downgrade credit). This class does not
 *   decide what to do with that; {@see SubscriptionService} does.
 * - Rounding is half-up, applied once, inside {@see Money::prorate()}.
 */
final readonly class ProrationCalculator
{
    public function calculate(
        Money $currentPlanAmount,
        Money $newPlanAmount,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $changedAt,
    ): ProrationResult {
        if ($periodEnd <= $periodStart) {
            throw new InvalidArgumentException('The billing period must end after it starts.');
        }

        $daysInPeriod = $this->wholeDaysBetween($periodStart, $periodEnd);

        if ($daysInPeriod === 0) {
            return ProrationResult::none(Money::zero($currentPlanAmount->currency));
        }

        $daysRemaining = $this->clamp(
            $this->wholeDaysBetween($changedAt, $periodEnd),
            0,
            $daysInPeriod,
        );

        $credit = $currentPlanAmount->prorate($daysRemaining, $daysInPeriod);
        $charge = $newPlanAmount->prorate($daysRemaining, $daysInPeriod);

        return new ProrationResult(
            daysInPeriod: $daysInPeriod,
            daysRemaining: $daysRemaining,
            creditFromCurrentPlan: $credit,
            chargeForNewPlan: $charge,
            amountDue: $charge->subtract($credit),
        );
    }

    /**
     * Whole days from $from to $to, floored at zero.
     *
     * Uses the UTC midnight of each date so that a change at 23:50 and one at
     * 00:10 the next morning differ by exactly one day, regardless of the
     * clock time the period happened to start at.
     */
    private function wholeDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $difference = $from->setTime(0, 0)->diff($to->setTime(0, 0));

        return $difference->invert === 1 ? 0 : (int) $difference->days;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
