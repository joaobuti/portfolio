<?php

declare(strict_types=1);

use App\DTOs\Billing\ChargeResult;
use App\DTOs\Subscriptions\ChangePlanData;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoicePaid;
use App\Events\Subscriptions\SubscriptionRenewed;
use App\Exceptions\Billing\InvoiceNotChargeableException;
use App\Exceptions\Subscriptions\PlanChangeNotAllowedException;
use App\Listeners\Billing\IssueInvoiceOnRenewal;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentGateway;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    freezeClockAt('2026-07-01 00:05:00');
    config()->set('risk.enabled', false);
});

it('rolls back an attempt and settlement together so retries retain the gateway key', function (): void {
    $keys = [];
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('charge')->twice()->andReturnUsing(
        function (Money $amount, string $customer, string $key) use (&$keys): ChargeResult {
            $keys[] = $key;

            return ChargeResult::succeeded('same-payment');
        },
    );
    app()->instance(PaymentGateway::class, $gateway);
    Event::listen(InvoicePaid::class, function (): void {
        throw new RuntimeException('Simulated failure before settlement commit');
    });
    $invoice = Invoice::factory()->create();
    $service = app(BillingService::class);

    expect(fn () => $service->collect($invoice))->toThrow(RuntimeException::class);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->fresh()?->attempt_count)->toBe(0)
        ->and($invoice->attempts()->count())->toBe(0);

    Event::forget(InvoicePaid::class);
    $service->collect($invoice->fresh());
    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->attempts()->count())->toBe(1);
});

it('refuses early collection before the persisted collection date', function (string $column): void {
    $invoice = Invoice::factory()->create([$column => CarbonImmutable::now()->addDay()]);
    try {
        app(BillingService::class)->collect($invoice);
        test()->fail('Early collection was accepted.');
    } catch (InvoiceNotChargeableException $exception) {
        expect($exception->context()['reason'])->toBe('not_due');
    }
    expect($invoice->attempts()->count())->toBe(0);
})->with(['due_at', 'next_attempt_at']);

it('invoices the captured renewal period and price after the model advances', function (): void {
    Queue::fake();
    $plan = Plan::factory()->priced('100.00')->create();
    $subscription = Subscription::factory()->for($plan, 'plan')->period(
        CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-08-01'),
    )->create();
    $serialized = serialize(new SubscriptionRenewed($subscription, CarbonImmutable::parse('2026-07-01')));
    $subscription->update([
        'current_period_start' => CarbonImmutable::parse('2026-08-01'),
        'current_period_end' => CarbonImmutable::parse('2026-09-01'),
    ]);
    $plan->update(['amount' => Money::ofMinor(20_000, Currency::BRL)]);
    app(IssueInvoiceOnRenewal::class)(unserialize($serialized));

    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->period_start?->toDateString())->toBe('2026-07-01')
        ->and($invoice->period_end?->toDateString())->toBe('2026-08-01')
        ->and($invoice->subtotal->minorUnits)->toBe(10_000);
});

it('revalidates a stale subscription before changing its plan', function (): void {
    $subscription = Subscription::factory()->create();
    $newPlan = Plan::factory()->create();
    Subscription::query()->whereKey($subscription->id)->update(['status' => SubscriptionStatus::Canceled]);
    expect(fn () => app(SubscriptionService::class)->changePlan($subscription, new ChangePlanData($newPlan->code)))
        ->toThrow(PlanChangeNotAllowedException::class);
    expect($subscription->changes()->count())->toBe(0);
});

it('quotes zero for a scheduled downgrade and a credit for an immediate one', function (): void {
    $subscription = Subscription::factory()->for(Plan::factory()->priced('100.00'), 'plan')->period(
        CarbonImmutable::parse('2026-06-16'), CarbonImmutable::parse('2026-07-16'),
    )->create();
    $cheaper = Plan::factory()->priced('50.00')->create();
    $service = app(SubscriptionService::class);
    expect($service->previewPlanChange($subscription, $cheaper->code)->amountDue->isZero())->toBeTrue()
        ->and($service->previewPlanChange($subscription, $cheaper->code, true)->amountDue->isNegative())->toBeTrue();
});
