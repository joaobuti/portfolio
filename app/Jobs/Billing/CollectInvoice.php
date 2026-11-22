<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Exceptions\Billing\InvoiceNotChargeableException;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Charges one invoice.
 *
 * Modelled on {@see \App\Jobs\Subscriptions\ProcessSubscriptionRenewal}, with
 * one important difference: **its retries are not the dunning schedule.**
 *
 * The queue retries here exist for infrastructure faults — the database blinked,
 * the worker was killed mid-charge. A declined card is not a fault; it is an
 * answer, and it is handled by {@see BillingService::collect()} recording the
 * attempt and deciding whether to chase. Conflating the two would retry a
 * declined card three times in ninety seconds, which is three gateway fees and
 * no new information.
 *
 * That is why `$tries` is low and the backoff is short. The real retry cadence
 * lives in `config('billing.dunning.retry_days')` and is driven by the sweep.
 */
final class CollectInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Infrastructure faults only. A declined card never gets here. */
    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $invoiceUuid)
    {
        $queue = config('billing.queue');
        $this->onQueue(is_string($queue) ? $queue : 'billing');
    }

    public function uniqueId(): string
    {
        return $this->invoiceUuid;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function retryUntil(): DateTimeInterface
    {
        $delay = $this->delay;
        $availableAt = is_int($delay) || $delay instanceof DateTimeInterface || $delay instanceof DateInterval
            ? $this->availableAt($delay)
            : $this->currentTime();

        // Laravel stores this deadline when it creates the queue payload.
        // A future due date must not consume the infrastructure retry window.
        return Carbon::createFromTimestamp(max($this->currentTime(), $availableAt))->addHour();
    }

    public function handle(BillingService $billing): void
    {
        $invoice = Invoice::query()
            ->with('customer')
            ->where('uuid', $this->invoiceUuid)
            ->first();

        if ($invoice === null) {
            Log::info('Skipped collection for an invoice that no longer exists', [
                'invoice_uuid' => $this->invoiceUuid,
            ]);

            return;
        }

        try {
            $billing->collect($invoice);
        } catch (InvoiceNotChargeableException $exception) {
            // Settled by another path between dispatch and execution, or
            // degraded. Either way there is nothing to do and nothing worth
            // failing the job over — retrying would never change the answer.
            Log::info('Invoice was no longer chargeable when collection ran', [
                'invoice_uuid' => $this->invoiceUuid,
                'reason' => $exception->context()['reason'],
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Invoice collection exhausted its retries', [
            'invoice_uuid' => $this->invoiceUuid,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
