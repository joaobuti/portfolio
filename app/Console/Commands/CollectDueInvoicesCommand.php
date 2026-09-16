<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\CollectInvoice;
use App\Models\Invoice;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Queues a collection job for every invoice whose next attempt has come due.
 *
 * The dunning cycle's engine. It is deliberately dumb: the decision about
 * *when* to try again was made by {@see \App\Services\Billing\BillingService}
 * at the moment the previous attempt failed, and written to
 * `invoices.next_attempt_at`. This command reads that column and nothing else.
 *
 * That split matters. A sweep that recomputed the retry schedule would be a
 * second implementation of the same policy, and the two would drift the first
 * time someone changed `config('billing.dunning.retry_days')` and forgot one
 * of them.
 *
 * Structure mirrors {@see RenewDueSubscriptionsCommand}, for the same reasons:
 * it dispatches rather than charges, so a slow gateway can never hold up the
 * sweep; and `lazyById` rather than `chunk`, because the jobs it queues mutate
 * the very rows it is paginating over.
 */
final class CollectDueInvoicesCommand extends Command
{
    protected $signature = 'invoices:collect
                            {--as-of= : Run as if it were this moment (ISO 8601), for replaying a dunning day locally}
                            {--dry-run : List what would be queued without queueing anything}';

    protected $description = 'Queue a collection job for every invoice whose next dunning attempt is due';

    public function handle(Clock $clock): int
    {
        $asOf = $this->option('as-of') !== null
            ? CarbonImmutable::parse((string) $this->option('as-of'))
            : $clock->now();

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf(
            'Sweeping invoices due as of %s%s',
            $asOf->toIso8601String(),
            $dryRun ? ' (dry run)' : '',
        ));

        $queued = 0;

        Invoice::query()
            ->dueForCollection($asOf)
            ->orderBy('id')
            ->lazyById()
            ->each(function (Invoice $invoice) use ($dryRun, &$queued): void {
                $queued++;

                if ($dryRun) {
                    $this->components->twoColumnDetail(
                        $invoice->uuid,
                        sprintf('attempt %d · %s', $invoice->attempt_count + 1, $invoice->total->format()),
                    );

                    return;
                }

                CollectInvoice::dispatch($invoice->uuid);
            });

        $this->components->info(
            $dryRun
                ? "{$queued} invoice(s) would be queued."
                : "{$queued} collection job(s) queued.",
        );

        Log::info('Invoice collection sweep finished', [
            'as_of' => $asOf->toIso8601String(),
            'queued' => $queued,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
