<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'grants_access' => $this->status->grantsAccess(),
            ],
            'plan' => PlanResource::make($this->whenLoaded('plan')),
            'pending_plan' => PlanResource::make($this->whenLoaded('pendingPlan')),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period' => [
                'start' => $this->current_period_start->toIso8601String(),
                'end' => $this->current_period_end->toIso8601String(),
            ],
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'changes' => SubscriptionChangeResource::collection($this->whenLoaded('changes')),
        ];
    }
}
