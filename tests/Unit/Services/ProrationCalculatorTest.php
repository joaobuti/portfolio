<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Services\Subscriptions\ProrationCalculator;
use App\Support\Money;

/**
 * A 30-day June is used throughout so the arithmetic is checkable by hand:
 * half a month of R$ 29,90 is R$ 14,95, and half of R$ 99,90 is R$ 49,95.
 */
beforeEach(function (): void {
    $this->calculator = new ProrationCalculator;
    $this->periodStart = new DateTimeImmutable('2026-06-01 00:00:00');
    $this->periodEnd = new DateTimeImmutable('2026-07-01 00:00:00');
});

function prorate(string $from, string $to, string $at): App\DTOs\Subscriptions\ProrationResult
{
    return test()->calculator->calculate(
        currentPlanAmount: Money::ofMajor($from, Currency::BRL),
        newPlanAmount: Money::ofMajor($to, Currency::BRL),
        periodStart: test()->periodStart,
        periodEnd: test()->periodEnd,
        changedAt: new DateTimeImmutable($at),
    );
}

describe('upgrading halfway through the period', function (): void {
    it('charges the difference for the days that remain', function (): void {
        $result = prorate('29.90', '99.90', '2026-06-16 09:00:00');

        expect($result->daysInPeriod)->toBe(30)
            ->and($result->daysRemaining)->toBe(15)
            ->and($result->creditFromCurrentPlan->toDecimal())->toBe('14.95')
            ->and($result->chargeForNewPlan->toDecimal())->toBe('49.95')
            ->and($result->amountDue->toDecimal())->toBe('35.00')
            ->and($result->isChargeable())->toBeTrue();
    });

    it('ignores the time of day, in the customer favour', function (): void {
        $earlyMorning = prorate('29.90', '99.90', '2026-06-16 00:01:00');
        $lateNight = prorate('29.90', '99.90', '2026-06-16 23:59:00');

        expect($earlyMorning->amountDue->toDecimal())
            ->toBe($lateNight->amountDue->toDecimal());
    });
});

describe('downgrading', function (): void {
    it('produces a credit that mirrors the equivalent upgrade', function (): void {
        $result = prorate('99.90', '29.90', '2026-06-16 09:00:00');

        expect($result->isCredit())->toBeTrue()
            ->and($result->amountDue->toDecimal())->toBe('-35.00');
    });
});

describe('boundaries', function (): void {
    it('charges the full difference on the first day', function (): void {
        expect(prorate('29.90', '99.90', '2026-06-01 00:00:00')->amountDue->toDecimal())
            ->toBe('70.00');
    });

    it('charges a single day on the last day of the period', function (): void {
        $result = prorate('29.90', '99.90', '2026-06-30 23:59:59');

        expect($result->daysRemaining)->toBe(1)
            ->and($result->amountDue->toDecimal())->toBe('2.33');
    });

    it('clamps to zero days once the period has passed', function (): void {
        $result = prorate('29.90', '99.90', '2026-08-01 00:00:00');

        expect($result->daysRemaining)->toBe(0)
            ->and($result->amountDue->isZero())->toBeTrue();
    });

    it('clamps to the whole period for a date before it started', function (): void {
        expect(prorate('29.90', '99.90', '2026-05-01 00:00:00')->daysRemaining)->toBe(30);
    });

    it('nets zero when the price does not change', function (): void {
        expect(prorate('49.90', '49.90', '2026-06-13 00:00:00')->amountDue->isZero())->toBeTrue();
    });

    it('refuses a period that ends before it starts', function (): void {
        test()->calculator->calculate(
            Money::zero(Currency::BRL),
            Money::zero(Currency::BRL),
            test()->periodEnd,
            test()->periodStart,
            test()->periodStart,
        );
    })->throws(InvalidArgumentException::class);
});

it('reports the remaining share for display only', function (): void {
    expect(prorate('29.90', '99.90', '2026-06-16 09:00:00')->remainingPercentage())->toBe(50.0);
});
