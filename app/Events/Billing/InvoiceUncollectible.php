<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dunning gave up.
 *
 * Distinct from a single PaymentFailed: this is the end of the road for the
 * invoice, and the point at which revenue recognition and access both change.
 */
final class InvoiceUncollectible
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $attempts,
    ) {}
}
