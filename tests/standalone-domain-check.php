<?php

declare(strict_types=1);

/**
 * Framework-free smoke check for the pure domain classes.
 *
 * The Pest suite in tests/Unit is the real test suite. This file exists so the
 * money and proration logic can be exercised with nothing but `php` — no
 * Composer, no vendor directory, no database. Useful in a bare container and a
 * decent proof that those classes really are framework-independent.
 *
 * Usage: php tests/standalone-domain-check.php
 */
spl_autoload_register(static function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $path = __DIR__.'/../app/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use App\DTOs\Subscriptions\ProrationResult;
use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\SubscriptionStatus;
use App\Services\Subscriptions\ProrationCalculator;
use App\Support\Money;

$passed = 0;
$failed = 0;

function check(string $description, callable $assertion): void
{
    global $passed, $failed;

    try {
        $result = $assertion();

        if ($result === true) {
            $passed++;
            echo "  \033[32m✓\033[0m {$description}\n";

            return;
        }

        $failed++;
        echo "  \033[31m✗\033[0m {$description} — got: ".var_export($result, true)."\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  \033[31m✗\033[0m {$description} — threw ".$e::class.': '.$e->getMessage()."\n";
    }
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

// ---------------------------------------------------------------- Money

section('Money');

check('parses a decimal string without float error', fn (): bool => Money::ofMajor('19.90', Currency::BRL)->minorUnits === 1990);

check('parses a whole number', fn (): bool => Money::ofMajor('20', Currency::BRL)->minorUnits === 2000);

check('parses a negative amount', fn (): bool => Money::ofMajor('-19.90', Currency::BRL)->minorUnits === -1990);

check('0.1 + 0.2 === 0.3 exactly', fn (): bool => Money::ofMajor('0.1', Currency::BRL)
    ->add(Money::ofMajor('0.2', Currency::BRL))
    ->equals(Money::ofMajor('0.3', Currency::BRL)));

check('formats BRL with the local separators', fn () => Money::ofMinor(123456, Currency::BRL)->format() === 'R$ 1.234,56'
        ? true
        : Money::ofMinor(123456, Currency::BRL)->format());

check('formats USD with the local separators', fn () => Money::ofMinor(123456, Currency::USD)->format() === '$ 1,234.56'
        ? true
        : Money::ofMinor(123456, Currency::USD)->format());

check('renders an exact decimal', fn (): bool => Money::ofMinor(5, Currency::BRL)->toDecimal() === '0.05');

check('renders a negative exact decimal', fn (): bool => Money::ofMinor(-5, Currency::BRL)->toDecimal() === '-0.05');

check('refuses to add different currencies', function (): bool {
    try {
        Money::ofMinor(100, Currency::BRL)->add(Money::ofMinor(100, Currency::USD));

        return false;
    } catch (App\Exceptions\CurrencyMismatchException) {
        return true;
    }
});

check('prorate rounds half up', fn (): bool => Money::ofMinor(100, Currency::BRL)->prorate(1, 8)->minorUnits === 13); // 12.5 -> 13

check('prorate rounds half up away from zero for negatives', fn (): bool => Money::ofMinor(-100, Currency::BRL)->prorate(1, 8)->minorUnits === -13);

check('prorate of a full period is the identity', fn (): bool => Money::ofMinor(9990, Currency::BRL)->prorate(30, 30)->minorUnits === 9990);

check('prorate of zero days is zero', fn (): bool => Money::ofMinor(9990, Currency::BRL)->prorate(0, 30)->isZero());

// ------------------------------------------------------- BillingInterval

section('BillingInterval — month arithmetic without overflow');

check('Jan 31 + 1 month is Feb 28 (non-leap), not Mar 3', fn () => BillingInterval::Monthly
    ->advance(new DateTimeImmutable('2027-01-31 10:00:00'))
    ->format('Y-m-d H:i:s') === '2027-02-28 10:00:00'
        ? true
        : BillingInterval::Monthly->advance(new DateTimeImmutable('2027-01-31 10:00:00'))->format('Y-m-d H:i:s'));

check('Jan 31 + 1 month is Feb 29 in a leap year', fn () => BillingInterval::Monthly
    ->advance(new DateTimeImmutable('2028-01-31'))
    ->format('Y-m-d') === '2028-02-29'
        ? true
        : BillingInterval::Monthly->advance(new DateTimeImmutable('2028-01-31'))->format('Y-m-d'));

check('Nov 30 + quarterly rolls into the next year', fn () => BillingInterval::Quarterly
    ->advance(new DateTimeImmutable('2026-11-30'))
    ->format('Y-m-d') === '2027-02-28'
        ? true
        : BillingInterval::Quarterly->advance(new DateTimeImmutable('2026-11-30'))->format('Y-m-d'));

check('Feb 29 + yearly clamps to Feb 28', fn () => BillingInterval::Yearly
    ->advance(new DateTimeImmutable('2028-02-29'))
    ->format('Y-m-d') === '2029-02-28'
        ? true
        : BillingInterval::Yearly->advance(new DateTimeImmutable('2028-02-29'))->format('Y-m-d'));

check('Dec 15 + monthly rolls the year over', fn () => BillingInterval::Monthly
    ->advance(new DateTimeImmutable('2026-12-15'))
    ->format('Y-m-d') === '2027-01-15'
        ? true
        : BillingInterval::Monthly->advance(new DateTimeImmutable('2026-12-15'))->format('Y-m-d'));

check('preserves the time of day', fn (): bool => BillingInterval::Monthly
    ->advance(new DateTimeImmutable('2026-03-10 14:35:07'))
    ->format('H:i:s') === '14:35:07');

// --------------------------------------------------- SubscriptionStatus

section('SubscriptionStatus — transition table');

check('trialing can become active', fn (): bool => SubscriptionStatus::Trialing->canTransitionTo(SubscriptionStatus::Active));

check('active cannot go back to trialing', fn (): bool => ! SubscriptionStatus::Active->canTransitionTo(SubscriptionStatus::Trialing));

check('past due can recover to active', fn (): bool => SubscriptionStatus::PastDue->canTransitionTo(SubscriptionStatus::Active));

check('expired is terminal', fn (): bool => SubscriptionStatus::Expired->isTerminal());

check('canceled can still expire at period end', fn (): bool => SubscriptionStatus::Canceled->canTransitionTo(SubscriptionStatus::Expired));

check('past due still grants access', fn (): bool => SubscriptionStatus::PastDue->grantsAccess());

check('canceled does not grant access', fn (): bool => ! SubscriptionStatus::Canceled->grantsAccess());

check('no status can transition to itself', fn (): bool => array_reduce(
    SubscriptionStatus::cases(),
    static fn (bool $carry, SubscriptionStatus $status): bool => $carry && ! $status->canTransitionTo($status),
    true,
));

// --------------------------------------------------- ProrationCalculator

section('ProrationCalculator');

$calculator = new ProrationCalculator;
$periodStart = new DateTimeImmutable('2026-06-01 00:00:00');
$periodEnd = new DateTimeImmutable('2026-07-01 00:00:00');

$upgrade = $calculator->calculate(
    currentPlanAmount: Money::ofMajor('29.90', Currency::BRL),
    newPlanAmount: Money::ofMajor('99.90', Currency::BRL),
    periodStart: $periodStart,
    periodEnd: $periodEnd,
    changedAt: new DateTimeImmutable('2026-06-16 09:00:00'),
);

check('counts a 30-day period', fn () => $upgrade->daysInPeriod === 30 ? true : $upgrade->daysInPeriod);

check('counts 15 remaining days from June 16', fn () => $upgrade->daysRemaining === 15 ? true : $upgrade->daysRemaining);

check('credits half of the old plan (R$ 14,95)', fn () => $upgrade->creditFromCurrentPlan->toDecimal() === '14.95'
        ? true
        : $upgrade->creditFromCurrentPlan->toDecimal());

check('charges half of the new plan (R$ 49,95)', fn () => $upgrade->chargeForNewPlan->toDecimal() === '49.95'
        ? true
        : $upgrade->chargeForNewPlan->toDecimal());

check('nets R$ 35,00 due on upgrade', fn () => $upgrade->amountDue->toDecimal() === '35.00'
        ? true
        : $upgrade->amountDue->toDecimal());

check('flags the upgrade as chargeable', fn (): bool => $upgrade->isChargeable());

$downgrade = $calculator->calculate(
    currentPlanAmount: Money::ofMajor('99.90', Currency::BRL),
    newPlanAmount: Money::ofMajor('29.90', Currency::BRL),
    periodStart: $periodStart,
    periodEnd: $periodEnd,
    changedAt: new DateTimeImmutable('2026-06-16 09:00:00'),
);

check('a downgrade produces a credit, not a charge', fn (): bool => $downgrade->isCredit());

check('the downgrade credit mirrors the upgrade charge', fn () => $downgrade->amountDue->toDecimal() === '-35.00'
        ? true
        : $downgrade->amountDue->toDecimal());

$sameDay = $calculator->calculate(
    Money::ofMajor('29.90', Currency::BRL),
    Money::ofMajor('99.90', Currency::BRL),
    $periodStart,
    $periodEnd,
    $periodStart,
);

check('changing on day one charges the full difference', fn () => $sameDay->amountDue->toDecimal() === '70.00'
        ? true
        : $sameDay->amountDue->toDecimal());

$lastMoment = $calculator->calculate(
    Money::ofMajor('29.90', Currency::BRL),
    Money::ofMajor('99.90', Currency::BRL),
    $periodStart,
    $periodEnd,
    new DateTimeImmutable('2026-06-30 23:59:59'),
);

check('changing on the final day of the period charges a single day', fn () => $lastMoment->daysRemaining === 1 && $lastMoment->amountDue->toDecimal() === '2.33'
        ? true
        : $lastMoment->daysRemaining.'d / '.$lastMoment->amountDue->toDecimal());

$afterEnd = $calculator->calculate(
    Money::ofMajor('29.90', Currency::BRL),
    Money::ofMajor('99.90', Currency::BRL),
    $periodStart,
    $periodEnd,
    new DateTimeImmutable('2026-08-01 00:00:00'),
);

check('a date past the period end clamps to zero days', fn () => $afterEnd->daysRemaining === 0 ? true : $afterEnd->daysRemaining);

$beforeStart = $calculator->calculate(
    Money::ofMajor('29.90', Currency::BRL),
    Money::ofMajor('99.90', Currency::BRL),
    $periodStart,
    $periodEnd,
    new DateTimeImmutable('2026-05-01 00:00:00'),
);

check('a date before the period start clamps to the full period', fn () => $beforeStart->daysRemaining === 30 ? true : $beforeStart->daysRemaining);

check('rejects an inverted period', function () use ($calculator, $periodStart, $periodEnd): bool {
    try {
        $calculator->calculate(
            Money::zero(Currency::BRL),
            Money::zero(Currency::BRL),
            $periodEnd,
            $periodStart,
            $periodStart,
        );

        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
});

check('a same-price change nets zero', fn (): bool => $calculator->calculate(
    Money::ofMajor('49.90', Currency::BRL),
    Money::ofMajor('49.90', Currency::BRL),
    $periodStart,
    $periodEnd,
    new DateTimeImmutable('2026-06-13'),
)->amountDue->isZero());

check('reports the remaining percentage for display', fn () => $upgrade->remainingPercentage() === 50.0 ? true : $upgrade->remainingPercentage());

check('ProrationResult::none() is inert', fn (): bool => ProrationResult::none(Money::zero(Currency::BRL))->amountDue->isZero());

// ------------------------------------------------------------- Summary

echo "\n".str_repeat('─', 56)."\n";
echo $failed === 0
    ? "\033[32m{$passed} passed, 0 failed\033[0m\n"
    : "\033[31m{$passed} passed, {$failed} failed\033[0m\n";

exit($failed === 0 ? 0 : 1);
