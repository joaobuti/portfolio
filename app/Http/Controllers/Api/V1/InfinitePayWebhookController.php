<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PaymentSignalRequest;
use App\Services\Billing\CheckoutNotificationService;
use Illuminate\Http\JsonResponse;

final class InfinitePayWebhookController extends Controller
{
    public function __invoke(PaymentSignalRequest $request, CheckoutNotificationService $service): JsonResponse
    {
        $service->accept($request->string('order_nsu')->toString(), $request->string('transaction_nsu')->toString(),
            $request->string($request->filled('invoice_slug') ? 'invoice_slug' : 'slug')->toString());

        return response()->json(['status' => 'accepted'], 202);
    }
}
