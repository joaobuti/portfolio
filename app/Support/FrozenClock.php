<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A clock that does not move, for tests and for the `--as-of` flag on the
 * renewal command (which lets you replay a day's billing run locally).
 */
final class FrozenClock implements Clock
{
    public function __construct(private CarbonImmutable $now) {}

    public static function at(string $timestamp): self
    {
        return new self(CarbonImmutable::parse($timestamp));
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function travelTo(CarbonImmutable $moment): void
    {
        $this->now = $moment;
    }
}
