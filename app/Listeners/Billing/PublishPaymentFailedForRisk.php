<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Events\Billing\PaymentFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Redis;

/**
 * Hands a failed payment to the Python risk worker.
 *
 * ## The envelope is the contract
 *
 * Everything below crosses a language boundary, so the shape is deliberate:
 *
 * - **`message_id`** is the idempotency key. Derived from the persisted attempt
 *   UUID, so a retry of this listener republishes the same logical identity.
 *   The worker records completion atomically with result publication.
 * - **`correlation_id`** comes from Laravel's `Context`, which the HTTP
 *   middleware populated and which follows the work onto queued jobs
 *   automatically. It is why a customer action can be traced from the API,
 *   through Redis, into a Python process — the reason that middleware was
 *   built before this listener existed.
 * - **Amounts are integers in minor units**, never floats or formatted
 *   strings. The same rule as everywhere else in this system (ADR-0002), and
 *   the one most likely to be broken by a JSON boundary.
 * - **`reason` is our mapped enum**, not a gateway string, so the worker never
 *   parses vendor vocabulary.
 *
 * ## Failure here must not fail the payment path
 *
 * Queued, so a Redis blip while scoring risk cannot roll back the recording of
 * a charge attempt. Risk scoring is advisory; billing is not.
 */
final class PublishPaymentFailedForRisk implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $queue = 'observability';

    public function shouldQueue(PaymentFailed $event): bool
    {
        return config('risk.enabled') === true && $event->invoice->customer()->whereNull('demo_workspace_id')->exists();
    }

    public function __invoke(PaymentFailed $event): void
    {
        if (config('risk.enabled') !== true || $event->invoice->customer()->whereNotNull('demo_workspace_id')->exists()) {
            return;
        }

        $invoice = $event->invoice;

        // The `messaging` connection, not the default one: it is the only
        // Redis connection with no key prefix, and a prefixed key would be
        // published to a list the Python worker never reads. See
        // config/database.php.
        Redis::connection('messaging')->rpush($this->stream(), json_encode([
            'message_id' => 'payment.failed:'.$event->attempt->uuid,
            'event' => 'payment.failed',
            'occurred_at' => $event->attempt->attempted_at->toIso8601String(),
            'correlation_id' => Context::get('correlation_id'),
            'data' => [
                'invoice_uuid' => $invoice->uuid,
                'customer_uuid' => $invoice->customer->uuid,
                'subscription_uuid' => $invoice->subscription?->uuid,
                'amount_minor' => $invoice->total->minorUnits,
                'currency' => $invoice->total->currency->value,
                'reason' => $event->reason->value,
                'attempt_number' => $event->attemptNumber,
                'will_retry' => $event->willRetry,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function stream(): string
    {
        $key = config('risk.streams.events');

        return is_string($key) ? $key : 'risk:events';
    }
}
