<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class InvoiceCatalog
{
    /** @return LengthAwarePaginator<int, Invoice> */
    public function forSubscription(Subscription $subscription): LengthAwarePaginator
    {
        return Invoice::query()->with('checkout')->where('subscription_id', $subscription->id)->latest('id')->paginate(20);
    }
}
