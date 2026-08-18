<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SubscriptionChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubscriptionChange
 */
final class SubscriptionChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'from_plan' => $this->whenLoaded('fromPlan', fn (): string => $this->fromPlan->code),
            'to_plan' => $this->whenLoaded('toPlan', fn (): string => $this->toPlan->code),
            'proration_amount' => $this->proration_amount,
            'period' => [
                'days_total' => $this->days_in_period,
                'days_remaining' => $this->days_remaining,
            ],
            'effective_at' => $this->effective_at->toIso8601String(),
            'requested_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
