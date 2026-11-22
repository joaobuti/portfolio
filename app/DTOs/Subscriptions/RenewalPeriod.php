<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

use App\Support\Money;
use DateTimeImmutable;

final readonly class RenewalPeriod
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public Money $amount,
    ) {}
}
