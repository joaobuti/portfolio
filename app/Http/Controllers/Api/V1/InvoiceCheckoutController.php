<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;

final class InvoiceCheckoutController extends Controller
{
    public function store(Invoice $invoice, BillingService $billing): JsonResponse
    {
        $this->authorize('checkout', $invoice);

        return InvoiceResource::make($billing->checkout($invoice)->load('checkout'))->response()->setStatusCode(202);
    }
}
