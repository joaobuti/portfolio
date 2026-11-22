<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Invoice;
use App\Services\Billing\BillingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExpireAdjustmentInvoice implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $invoiceUuid)
    {
        $queue = config('billing.queue');
        $this->onQueue(is_string($queue) ? $queue : 'billing');
    }

    public function uniqueId(): string
    {
        return $this->invoiceUuid;
    }

    public function handle(BillingService $billing): void
    {
        $invoice = Invoice::query()->where('uuid', $this->invoiceUuid)->first();
        if ($invoice !== null) {
            $billing->expireAdjustment($invoice);
        }
    }
}
