<?php

declare(strict_types=1);

use App\Enums\BillingInterval;

/**
 * These cases are the reason `advance()` exists instead of `modify('+1 month')`.
 * Native PHP month arithmetic overflows: January 31st plus one month is March
 * 3rd, which would move a customer's anniversary date permanently and skip an
 * invoice on the way.
 */
it('never overflows into the following month', function (
    BillingInterval $interval,
    string $from,
    string $expected,
): void {
    expect($interval->advance(new DateTimeImmutable($from))->format('Y-m-d'))->toBe($expected);
})->with([
    'Jan 31 monthly, non-leap year' => [BillingInterval::Monthly, '2027-01-31', '2027-02-28'],
    'Jan 31 monthly, leap year' => [BillingInterval::Monthly, '2028-01-31', '2028-02-29'],
    'Jan 30 monthly' => [BillingInterval::Monthly, '2027-01-30', '2027-02-28'],
    'Mar 31 monthly into 30-day April' => [BillingInterval::Monthly, '2026-03-31', '2026-04-30'],
    'Nov 30 quarterly across the year boundary' => [BillingInterval::Quarterly, '2026-11-30', '2027-02-28'],
    'Feb 29 yearly onto a non-leap year' => [BillingInterval::Yearly, '2028-02-29', '2029-02-28'],
    'Dec 15 monthly rolls the year' => [BillingInterval::Monthly, '2026-12-15', '2027-01-15'],
    'ordinary mid-month date' => [BillingInterval::Monthly, '2026-06-10', '2026-07-10'],
]);

it('keeps the time of day so billing does not drift', function (): void {
    expect(
        BillingInterval::Monthly
            ->advance(new DateTimeImmutable('2026-03-10 14:35:07'))
            ->format('Y-m-d H:i:s'),
    )->toBe('2026-04-10 14:35:07');
});

it('is stable across twelve consecutive monthly renewals', function (): void {
    $date = new DateTimeImmutable('2026-01-31');

    foreach (range(1, 12) as $ignored) {
        $date = BillingInterval::Monthly->advance($date);
    }

    // Once clamped to 28/30, the anniversary does not walk back up to 31 —
    // this is the documented, intentional trade-off of day clamping.
    expect($date->format('Y-m-d'))->toBe('2027-01-28');
});
