<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\InvoiceCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InvoiceController extends Controller
{
    public function index(Subscription $subscription, InvoiceCatalog $catalog): AnonymousResourceCollection
    {
        $this->authorize('view', $subscription);

        return InvoiceResource::collection($catalog->forSubscription($subscription));
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $this->authorize('view', $invoice);

        return InvoiceResource::make($invoice->load('checkout'));
    }
}
