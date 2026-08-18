<?php

declare(strict_types=1);

namespace App\Enums;

use DateTimeImmutable;

/**
 * How often a plan bills.
 *
 * `advance()` deliberately does not use PHP's native `modify('+1 month')`.
 * Native month arithmetic overflows: January 31st plus one month lands on
 * March 3rd, which would silently skip a customer's February invoice and move
 * their anniversary date forever. We clamp to the last valid day of the target
 * month instead, which is what every billing system in production does.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }

    /**
     * Move a date forward by one billing period, without month overflow.
     */
    public function advance(DateTimeImmutable $from): DateTimeImmutable
    {
        $day = (int) $from->format('j');
        $month = (int) $from->format('n') + $this->months();
        $year = (int) $from->format('Y') + intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;

        $lastDayOfTargetMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));

        return $from->setDate($year, $month, min($day, $lastDayOfTargetMonth));
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Yearly => 'Anual',
        };
    }
}
