<?php

declare(strict_types=1);

namespace App\Services\Subscriptions;

use App\Models\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read model for the plan list.
 *
 * The catalogue is read on nearly every pricing page and changes a handful of
 * times a year, which is the textbook shape for a cache. What matters is the
 * invalidation strategy: this cache is **event-invalidated, not TTL-expired**.
 * A TTL would mean either serving a stale price after a change (bad) or
 * hammering the database to avoid it (also bad). {@see \App\Observers\PlanObserver}
 * flushes the key the moment a plan is written.
 *
 * The TTL that is set acts purely as a safety net against a missed flush.
 */
final readonly class PlanCatalog
{
    public const CACHE_KEY = 'plans:active';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return Collection<int, Plan>
     */
    public function active(): Collection
    {
        /** @var Collection<int, Plan> */
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            static fn (): Collection => Plan::query()->active()->ordered()->get(),
        );
    }

    public function findByCode(string $code): ?Plan
    {
        return $this->active()->firstWhere('code', $code);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
