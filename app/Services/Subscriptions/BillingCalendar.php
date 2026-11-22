<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\DTOs\Subscriptions\RenewalPeriod;
use App\Enums\BillingInterval;
use App\Support\Money;
use Carbon\CarbonImmutable;

final readonly class BillingCalendar
{
    public function dueAt(CarbonImmutable $periodStart): CarbonImmutable
    {
        $local = $periodStart->setTimezone('America/Sao_Paulo');
        $due = $local->startOfMonth()->day(10);
        if ($local->startOfDay() > $due) {
            $due = $due->addMonthNoOverflow();
        }

        return $due->setTimezone('UTC');
    }

    public function firstPeriod(CarbonImmutable $start, BillingInterval $interval, Money $amount): RenewalPeriod
    {
        $local = $start->setTimezone('America/Sao_Paulo');
        $anchor = $local->startOfMonth()->day(10);
        if ($local->startOfDay() < $anchor) {
            $anchor = $anchor->subMonthNoOverflow();
        }
        $end = CarbonImmutable::instance($interval->advance($anchor));
        $days = (int) $anchor->diffInDays($end);
        $remaining = (int) $local->startOfDay()->diffInDays($end);

        return new RenewalPeriod($start, $end->setTimezone('UTC'), $amount->prorate($remaining, $days));
    }

    public function prorationStart(CarbonImmutable $start, CarbonImmutable $end, BillingInterval $interval): CarbonImmutable
    {
        $localEnd = $end->setTimezone('America/Sao_Paulo');
        if (! $this->isAnchored($end)) {
            return $start;
        }
        $fullStart = $localEnd->subMonthsNoOverflow($interval->months())->setTimezone('UTC');

        return $fullStart->min($start);
    }

    public function prorationTimezone(CarbonImmutable $end): string
    {
        return $this->isAnchored($end) ? 'America/Sao_Paulo' : $end->getTimezone()->getName();
    }

    private function isAnchored(CarbonImmutable $end): bool
    {
        $local = $end->setTimezone('America/Sao_Paulo');

        return $local->day === 10 && $local->eq($local->startOfDay());
    }
}
