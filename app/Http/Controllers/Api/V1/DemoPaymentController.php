<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Demo\DemoPayments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DemoPaymentController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, DemoPayments $payments): InvoiceResource
    {
        $this->authorize('checkout', $invoice);
        $request->validate(['outcome' => ['required', Rule::in(['approved', 'declined', 'pending'])]]);

        return InvoiceResource::make($payments->simulate($invoice, $request->string('outcome')->toString())->load('checkout'));
    }
}
