<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Models\ChargeAttempt;
use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Money arrived.
 *
 * Carries the successful attempt because the gateway reference on it is what
 * reconciliation matches against the settlement file.
 */
final readonly class InvoicePaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ChargeAttempt $attempt,
    ) {}
}
