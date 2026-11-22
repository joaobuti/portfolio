<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Billing\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaymentSignalRequest;
use App\Models\Invoice;
use App\Services\Billing\CheckoutNotificationService;
use Illuminate\Http\JsonResponse;

final class InvoicePaymentCheckController extends Controller
{
    public function store(PaymentSignalRequest $request, Invoice $invoice, CheckoutNotificationService $service): JsonResponse
    {
        $checkout = $invoice->load('checkout')->checkout;
        if ($checkout === null) {
            throw new CheckoutException('This invoice has no hosted checkout.', 422);
        }
        $service->accept($checkout->order_reference, $request->string('transaction_nsu')->toString(),
            $request->string($request->filled('invoice_slug') ? 'invoice_slug' : 'slug')->toString(), retry: true);

        return response()->json(['status' => 'accepted'], 202);
    }
}
