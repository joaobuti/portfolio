<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Enums\PaymentFailureReason;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A charge did not go through.
 *
 * ## This is the event the Python risk worker consumes
 *
 * It has been named in the roadmap and in ADR-0003 since the first commit, and
 * until now nothing produced it. That is why the worker could not be built:
 * there was no event to consume.
 *
 * Its shape matters more than most, because it is about to cross a language
 * boundary. Two consequences, both deliberate:
 *
 * - **`attemptNumber` is carried, not derived.** A consumer that has to query
 *   back into Postgres to learn how many times we have tried is a consumer
 *   coupled to our schema. The number is a fact about this event.
 * - **The reason is the mapped enum, not the gateway's string.** The worker
 *   scores delinquency risk, and a card that expired means something quite
 *   different from a network blip. Making it parse vendor vocabulary would
 *   make swapping gateways a change in two repositories.
 *
 * The correlation id rides along automatically via Laravel's `Context`, which
 * is why the middleware was built before this event existed — see
 * docs/ARCHITECTURE.md.
 */
final readonly class PaymentFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ChargeAttempt $attempt,
        public PaymentFailureReason $reason,
        public int $attemptNumber,
        public bool $willRetry,
    ) {}
}
