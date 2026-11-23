<?php

declare(strict_types=1);

namespace App\Jobs\Subscriptions;

use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rolls one subscription into its next billing period.
 *
 * One job per subscription rather than one job for the whole batch. A batch
 * job that fails halfway leaves you guessing which customers were processed;
 * per-subscription jobs fail, retry and dead-letter independently, and the
 * queue depth doubles as a progress bar.
 *
 * Safety properties, in the order they matter:
 *
 * - **Unique while queued.** `ShouldBeUnique` stops the daily scheduler from
 *   stacking a second job on a subscription whose first one is still waiting.
 * - **Idempotent when it runs.** Uniqueness is a lock, and locks expire; the
 *   real guarantee is that {@see SubscriptionService::renew()} re-reads the
 *   row and no-ops if the period already moved. Never rely on the queue alone.
 * - **Bounded retries.** Five attempts with growing backoff, then the failed
 *   jobs table. A subscription that cannot renew is a support problem, not
 *   something to retry forever.
 */
final class ProcessSubscriptionRenewal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Give up entirely after this long, however many attempts are left. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $subscriptionUuid)
    {
        $this->onQueue('billing');
    }

    public function uniqueId(): string
    {
        return $this->subscriptionUuid;
    }

    /**
     * Growing backoff: a transient database blip clears in seconds, a broken
     * downstream takes minutes.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addHours(6);
    }

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscription = Subscription::query()->automated()
            ->where('uuid', $this->subscriptionUuid)
            ->first();

        if ($subscription === null) {
            // Deleted between scheduling and execution. Nothing to do, and
            // nothing worth failing the job over.
            Log::info('Skipped renewal for a subscription that no longer exists', [
                'subscription_uuid' => $this->subscriptionUuid,
            ]);

            return;
        }

        $subscriptions->renew($subscription);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Subscription renewal exhausted its retries', [
            'subscription_uuid' => $this->subscriptionUuid,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
