<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Pricing\PriceQuote;
use App\DTOs\Pricing\QuoteLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceQuote
 */
final class PriceQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'taxable' => $this->taxable,
            'tax' => $this->tax,
            'total' => $this->total,
            'tax_region' => $this->taxRegion,
            'lines' => array_map(
                static fn (QuoteLine $line): array => [
                    'type' => $line->type->value,
                    'code' => $line->code,
                    'description' => $line->description,
                    'amount' => $line->amount,
                ],
                $this->lines,
            ),
            'coupon' => $this->coupon === null ? null : [
                'code' => $this->coupon->code,
                'applied' => $this->coupon->applied,
                'reason' => $this->coupon->reason?->value,
                'message' => $this->coupon->reason?->describe(),
            ],
            // Surfaced rather than hidden: a client that shows a total must be
            // able to tell the customer it is provisional.
            'degraded' => $this->degraded,
        ];
    }
}
