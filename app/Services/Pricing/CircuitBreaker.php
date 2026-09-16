<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Illuminate\Support\Facades\Cache;

/**
 * Stops a sick dependency from becoming our outage.
 *
 * ## Why this exists at all
 *
 * The pricing service is called synchronously while a customer waits. Without
 * a breaker, a dependency that has started timing out costs every single plan
 * change the full client timeout — the failure is not just repeated, it is
 * repeated at the slowest possible speed, and the queue of waiting workers is
 * what actually takes the site down. The breaker turns "slow failure, every
 * time" into "instant fallback, until it recovers".
 *
 * ## The shape
 *
 * Consecutive failures are counted. At the threshold the circuit opens for a
 * fixed window, during which every call short-circuits without touching the
 * network. The first call after the window is allowed through as a probe: it
 * either succeeds and resets everything, or fails and re-opens the circuit.
 *
 * A success resets the counter, so an occasional blip never accumulates into
 * an open circuit over hours.
 *
 * ## Why the cache and not memory
 *
 * State lives in Redis so the web containers and the queue workers share one
 * view of the dependency's health. Per-process breakers in a fleet of eight
 * containers mean the dependency gets hit eight times as hard before anyone
 * decides it is down, and it recovers unevenly afterwards.
 *
 * The trade-off is honest: this is not a distributed-consensus breaker. Two
 * containers can race and both let a probe through. That is harmless — the
 * cost of an extra probe is one request, and the alternative is a lock in the
 * path of every quote.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private string $key,
        private int $failureThreshold,
        private int $openSeconds,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{failure_threshold: int, open_seconds: int, cache_key: string} $config */
        $config = config('pricing.breaker');

        return new self(
            key: $config['cache_key'],
            failureThreshold: $config['failure_threshold'],
            openSeconds: $config['open_seconds'],
        );
    }

    /**
     * True when calls should skip the network entirely.
     *
     * The open marker carries its own TTL, so the circuit half-opens by simply
     * expiring. There is no timer to run and nothing to sweep.
     */
    public function isOpen(): bool
    {
        return Cache::get($this->openKey()) !== null;
    }

    /**
     * A call came back healthy: forget the failures and close the circuit.
     */
    public function recordSuccess(): void
    {
        Cache::forget($this->failureKey());
        Cache::forget($this->openKey());
    }

    /**
     * A call failed at the transport level. Opens the circuit at the threshold.
     */
    public function recordFailure(): void
    {
        $failures = $this->increment();

        if ($failures >= $this->failureThreshold) {
            Cache::put($this->openKey(), true, $this->openSeconds);
        }
    }

    /**
     * Consecutive failures recorded so far. Exposed for logging and tests —
     * nothing branches on it outside this class.
     */
    public function failureCount(): int
    {
        $count = Cache::get($this->failureKey(), 0);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Wipe all state. Used when the breaker is reconfigured and by tests that
     * need a known starting point.
     */
    public function reset(): void
    {
        $this->recordSuccess();
    }

    /**
     * The counter expires on its own so that failures separated by hours are
     * never mistaken for a burst. Without a TTL, five unrelated blips across a
     * week would open the circuit for no reason.
     */
    private function increment(): int
    {
        $key = $this->failureKey();

        if (Cache::get($key) === null) {
            Cache::put($key, 1, $this->openSeconds * 2);

            return 1;
        }

        // Not atomic across every cache driver, but it is on Redis, which is
        // what this runs on. A missed increment under a race delays the
        // breaker by one failure — an acceptable price for no lock.
        //
        // `increment()` returns false on a driver that cannot do it; treating
        // that as a single failure keeps the breaker conservative rather than
        // silently never opening.
        $incremented = Cache::increment($key);

        return is_int($incremented) ? $incremented : 1;
    }

    private function failureKey(): string
    {
        return $this->key.':failures';
    }

    private function openKey(): string
    {
        return $this->key.':open';
    }
}
