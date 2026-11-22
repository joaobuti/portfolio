<?php

declare(strict_types=1);

namespace App\Listeners\Subscriptions;

use App\Events\Subscriptions\SubscriptionCanceled;
use App\Events\Subscriptions\SubscriptionCreated;
use App\Events\Subscriptions\SubscriptionExpired;
use App\Events\Subscriptions\SubscriptionPlanChanged;
use App\Events\Subscriptions\SubscriptionRenewed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Writes a structured, machine-parseable line for every lifecycle event.
 *
 * Queued, because an audit trail must never be able to slow down or fail the
 * request that produced it. `$afterCommit` matters just as much: without it a
 * worker can pick the job up before the transaction that created the
 * subscription has committed, and log an event for a row nobody can read yet.
 *
 * Every line carries the same field names, which is what makes
 * `subscription_uuid` a searchable dimension in a log aggregator rather than
 * a string buried in prose.
 */
final class RecordSubscriptionActivity implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public string $queue = 'observability';

    public function handleCreated(SubscriptionCreated $event): void
    {
        $this->write('subscription.created', $event->subscription->uuid, [
            'plan' => $event->subscription->plan->code,
            'status' => $event->subscription->status->value,
            'trial_ends_at' => $event->subscription->trial_ends_at?->toIso8601String(),
        ]);
    }

    public function handlePlanChanged(SubscriptionPlanChanged $event): void
    {
        $this->write('subscription.plan_changed', $event->subscription->uuid, [
            'from_plan' => $event->fromPlan->code,
            'to_plan' => $event->toPlan->code,
            'type' => $event->type->value,
            'amount_due_cents' => $event->proration->amountDue->minorUnits,
            'currency' => $event->proration->amountDue->currency->value,
            'days_remaining' => $event->proration->daysRemaining,
            'scheduled' => $event->subscription->hasScheduledPlanChange(),
        ]);
    }

    public function handleRenewed(SubscriptionRenewed $event): void
    {
        $this->write('subscription.renewed', $event->subscription->uuid, [
            'previous_period_end' => $event->previousPeriodEnd->toIso8601String(),
            'current_period_end' => $event->subscription->current_period_end->toIso8601String(),
            'plan' => $event->subscription->plan->code,
        ]);
    }

    public function handleCanceled(SubscriptionCanceled $event): void
    {
        $this->write('subscription.canceled', $event->subscription->uuid, [
            'immediately' => $event->immediately,
            'access_until' => $event->immediately
                ? $event->subscription->ended_at?->toIso8601String()
                : $event->subscription->current_period_end->toIso8601String(),
        ]);
    }

    public function handleExpired(SubscriptionExpired $event): void
    {
        $this->write('subscription.expired', $event->subscription->uuid, [
            'ended_at' => $event->subscription->ended_at?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $event, string $subscriptionUuid, array $context): void
    {
        Log::channel('activity')->info($event, [
            'event' => $event,
            'subscription_uuid' => $subscriptionUuid,
            ...$context,
        ]);
    }
}
