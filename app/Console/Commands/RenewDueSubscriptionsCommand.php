<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Subscriptions\ProcessSubscriptionRenewal;
use App\Models\Subscription;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Finds subscriptions whose paid period has run out and queues one renewal job
 * for each.
 *
 * The command dispatches; it does not renew. Keeping the scheduler's job to
 * "produce work" means a slow or failing renewal can never hold up the sweep,
 * and the sweep itself finishes in the time it takes to run one indexed query.
 *
 * `lazyById` rather than `chunk`: this query mutates the very rows it is
 * paginating over, and offset-based chunking would skip records as the result
 * set shifts underneath it.
 */
final class RenewDueSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:renew
                            {--as-of= : Run as if it were this moment (ISO 8601), for replaying a billing day locally}
                            {--dry-run : List what would be queued without queueing anything}';

    protected $description = 'Queue a renewal job for every subscription whose billing period has ended';

    public function handle(Clock $clock): int
    {
        $option = $this->option('as-of');
        $asOf = is_string($option)
            ? Carbon::parse($option)
            : Carbon::instance($clock->now());

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf(
            'Sweeping subscriptions due as of %s%s',
            $asOf->toIso8601String(),
            $dryRun ? ' (dry run)' : '',
        ));

        $queued = 0;

        Subscription::query()
            ->dueForRenewal($asOf)
            ->orderBy('id')
            ->lazyById()
            ->each(function (Subscription $subscription) use ($dryRun, &$queued): void {
                $queued++;

                if ($dryRun) {
                    $this->components->twoColumnDetail(
                        $subscription->uuid,
                        'due '.$subscription->current_period_end->toDateString(),
                    );

                    return;
                }

                ProcessSubscriptionRenewal::dispatch($subscription->uuid);
            });

        $this->components->info(
            $dryRun
                ? "{$queued} subscription(s) would be queued."
                : "{$queued} renewal job(s) queued.",
        );

        Log::info('Subscription renewal sweep finished', [
            'as_of' => $asOf->toIso8601String(),
            'queued' => $queued,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
