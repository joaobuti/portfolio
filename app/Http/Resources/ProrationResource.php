<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Subscriptions\ProrationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProrationResult
 */
final class ProrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'amount_due' => $this->amountDue,
            'credit_from_current_plan' => $this->creditFromCurrentPlan,
            'charge_for_new_plan' => $this->chargeForNewPlan,
            'period' => [
                'days_total' => $this->daysInPeriod,
                'days_remaining' => $this->daysRemaining,
                'remaining_percentage' => $this->remainingPercentage(),
            ],
            'is_charge' => $this->isChargeable(),
            'is_credit' => $this->isCredit(),
        ];
    }
}
