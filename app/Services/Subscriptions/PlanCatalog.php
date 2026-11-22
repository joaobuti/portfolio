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
    // Version the key because older entries contain serialized PHP objects.
    public const string CACHE_KEY = 'plans:active:v2';

    private const int CACHE_TTL_SECONDS = 3600;

    /**
     * @return Collection<int, Plan>
     */
    public function active(): Collection
    {
        /** @var list<array<string, mixed>> $attributes */
        $attributes = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            static fn (): array => Plan::query()->active()->ordered()->get()
                ->map(static fn (Plan $plan): array => $plan->getAttributes())
                ->all(),
        );

        // Cache allows no PHP classes to be unserialized. Hydrate scalar rows
        // here so money/enums keep their model casts without a database read.
        return Plan::hydrate($attributes);
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
