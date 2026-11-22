<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\CheckoutStatus;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid, 'status' => $this->status->value, 'kind' => $this->kind->value,
            'payment_deadline_at' => $this->payment_deadline_at?->toIso8601String(),
            'subtotal' => $this->subtotal, 'discount' => $this->discount, 'tax' => $this->tax, 'total' => $this->total,
            'period' => ['start' => $this->period_start?->toIso8601String(), 'end' => $this->period_end?->toIso8601String()],
            'due_at' => $this->due_at->toIso8601String(), 'paid_at' => $this->paid_at?->toIso8601String(),
            'pricing_degraded' => $this->pricing_degraded,
            'checkout' => $this->whenLoaded('checkout', fn (): ?array => $this->checkout === null ? null : $this->checkoutData($this->checkout)),
        ];
    }

    /** @return array<string, mixed> */
    private function checkoutData(PaymentCheckout $checkout): array
    {
        $status = $checkout->status === CheckoutStatus::AwaitingPayment && $checkout->expires_at <= app(Clock::class)->now()
            ? CheckoutStatus::Expired : $checkout->status;

        return ['id' => $checkout->uuid, 'status' => $status->value,
            'url' => $status === CheckoutStatus::AwaitingPayment && $this->status === InvoiceStatus::Open ? $checkout->url : null,
            'expires_at' => $checkout->expires_at->toIso8601String()];
    }
}
