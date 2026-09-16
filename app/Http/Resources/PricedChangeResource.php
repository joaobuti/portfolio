<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Pricing\PricedChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan-change preview: the proration, plus what tax and coupons do to it.
 *
 * The proration keys sit at the top level, in the shape the preview endpoint
 * has always emitted them. That is deliberate — this resource replaced the
 * proration-only one, and every existing client reading `amount_due` or
 * `period.days_remaining` keeps working. The pricing information is additive,
 * under its own key.
 *
 * @mixin PricedChange
 */
final class PricedChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $proration = $this->proration;

        return [
            'amount_due' => $proration->amountDue,
            'credit_from_current_plan' => $proration->creditFromCurrentPlan,
            'charge_for_new_plan' => $proration->chargeForNewPlan,
            'period' => [
                'days_total' => $proration->daysInPeriod,
                'days_remaining' => $proration->daysRemaining,
                'remaining_percentage' => $proration->remainingPercentage(),
            ],
            'is_charge' => $proration->isChargeable(),
            'is_credit' => $proration->isCredit(),

            'pricing' => PriceQuoteResource::make($this->quote),

            // False when the quote is degraded. A client should treat the
            // total as provisional and not offer to confirm the change.
            'chargeable' => $this->isChargeable(),
        ];
    }
}
