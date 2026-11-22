<?php

declare(strict_types=1);

namespace App\Events\Billing;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An invoice now exists and is owed.
 *
 * Fired inside the same transaction boundary that created it, so a listener
 * can never see an invoice that was rolled back.
 */
final readonly class InvoiceIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
