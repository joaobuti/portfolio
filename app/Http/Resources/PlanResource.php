<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            // Money serialises to { amount, currency, formatted } so clients
            // can do arithmetic on the integer and display the string without
            // reimplementing our formatting rules in three front-ends.
            'price' => $this->amount,
            'interval' => [
                'value' => $this->interval->value,
                'label' => $this->interval->label(),
            ],
            'trial_days' => $this->trial_days,
            'features' => $this->features,
        ];
    }
}
