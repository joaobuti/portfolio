<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Subscriptions\CreateSubscriptionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelSubscriptionRequest;
use App\Http\Requests\Api\V1\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin by design.
 *
 * Every action here does the same four things: turn a validated request into a
 * DTO, hand it to the service, load what the response needs, and return a
 * resource. There is no business rule in this file — if one ever appears, it
 * belongs in {@see SubscriptionService} where the console commands and queue
 * jobs can reach it too.
 *
 * Translating request to DTO is the controller's job precisely because it is
 * the only layer that should know an HTTP request exists.
 */
final class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->create(new CreateSubscriptionData(
            customerUuid: $request->string('customer')->toString(),
            planCode: $request->string('plan')->toString(),
            withTrial: $request->boolean('with_trial', default: true),
            startsAt: $request->date('starts_at')?->toDateTimeImmutable(),
        ));

        return SubscriptionResource::make($subscription->load('plan'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        return SubscriptionResource::make(
            $subscription->load(['plan', 'pendingPlan', 'changes.fromPlan', 'changes.toPlan']),
        );
    }

    /**
     * Cancel. Defaults to "at the end of the paid period"; pass
     * `immediately=true` to end access now.
     */
    public function destroy(
        CancelSubscriptionRequest $request,
        Subscription $subscription,
    ): SubscriptionResource {
        $canceled = $this->subscriptions->cancel(
            $subscription,
            immediately: $request->boolean('immediately'),
        );

        return SubscriptionResource::make($canceled->load('plan'));
    }
}
