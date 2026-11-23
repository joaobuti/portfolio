<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\RepriceDegradedInvoice;
use App\Models\Invoice;
use App\Support\Clock;
use Illuminate\Console\Command;

final class RepriceDegradedInvoicesCommand extends Command
{
    protected $signature = 'invoices:reprice-degraded';

    protected $description = 'Automatically recover degraded invoices using current pricing rules';

    public function handle(Clock $clock): int
    {
        Invoice::query()->automated()->where('status', InvoiceStatus::Open)->where('pricing_degraded', true)
            ->where(fn ($query) => $query->whereNull('pricing_retry_at')->orWhere('pricing_retry_at', '<=', $clock->now()))
            ->whereDoesntHave('checkout')->whereDoesntHave('attempts')->lazyById()
            ->each(static function (Invoice $invoice): void {
                RepriceDegradedInvoice::dispatch($invoice->uuid);
            });

        return self::SUCCESS;
    }
}
