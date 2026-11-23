<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Jobs\Billing\ExpireAdjustmentInvoice;
use App\Models\Invoice;
use App\Support\Clock;
use Illuminate\Console\Command;

final class ExpireAdjustmentInvoicesCommand extends Command
{
    protected $signature = 'invoices:expire-adjustments';

    protected $description = 'Revert unpaid upgrades after their payment deadline';

    public function handle(Clock $clock): int
    {
        Invoice::query()->automated()->where('kind', InvoiceKind::Adjustment)->where('status', InvoiceStatus::Open)
            ->where('payment_deadline_at', '<=', $clock->now())->lazyById()
            ->each(static function (Invoice $invoice): void {
                ExpireAdjustmentInvoice::dispatch($invoice->uuid);
            });

        return self::SUCCESS;
    }
}
