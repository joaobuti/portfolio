<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\CarbonImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }
}
