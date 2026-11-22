<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Subscriptions\ChangePlanData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePlanRequest;
use App\Http\Resources\PricedChangeResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;

/**
 * The plan of a subscription, treated as its own resource.
 *
 * A separate controller rather than another verb on SubscriptionController:
 * "change the plan" has its own validation, its own preview endpoint and its
 * own failure modes, and bolting it onto `update()` alongside every other
 * mutable field is how controllers grow into 400-line switch statements.
 */
final class SubscriptionPlanController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * What the change would cost, charging nothing.
     *
     * Shares proration arithmetic with `update()`. Tax and coupons are quoted
     * here; adjustment invoicing and collection are not connected yet.
     */
    public function preview(ChangePlanRequest $request, Subscription $subscription): PricedChangeResource
    {
        return PricedChangeResource::make(
            $this->subscriptions->quotePlanChange(
                $subscription,
                $request->string('plan')->toString(),
                $request->filled('coupon') ? $request->string('coupon')->toString() : null,
                $request->boolean('apply_immediately'),
            ),
        );
    }

    public function update(ChangePlanRequest $request, Subscription $subscription): SubscriptionResource
    {
        $updated = $this->subscriptions->changePlan($subscription, new ChangePlanData(
            planCode: $request->string('plan')->toString(),
            applyDowngradeImmediately: $request->boolean('apply_immediately'),
            quoteId: $request->filled('quote_id') ? $request->string('quote_id')->toString() : null,
            couponCode: $request->filled('coupon') ? $request->string('coupon')->toString() : null,
        ));

        return SubscriptionResource::make(
            $updated->load(['plan', 'pendingPlan', 'changes.fromPlan', 'changes.toPlan']),
        );
    }
}
