<?php

declare(strict_types=1);

use App\DTOs\Billing\ChargeResult;
use App\DTOs\Pricing\PriceQuote;
use App\DTOs\Subscriptions\ChangePlanData;
use App\DTOs\Subscriptions\CreateSubscriptionData;
use App\Enums\Currency;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentFailureReason;
use App\Enums\SubscriptionStatus;
use App\Events\Billing\InvoicePaid;
use App\Exceptions\Subscriptions\DuplicateActiveSubscriptionException;
use App\Exceptions\Subscriptions\PlanChangeNotAllowedException;
use App\Jobs\Billing\CollectInvoice;
use App\Jobs\Billing\ExpireAdjustmentInvoice;
use App\Jobs\Billing\RepriceDegradedInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\PaymentGateway;
use App\Services\Pricing\PricingClient;
use App\Services\Subscriptions\BillingCalendar;
use App\Services\Subscriptions\SubscriptionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->clock = freezeClockAt('2026-06-16 12:00:00');
    Queue::fake();
    Event::fake([InvoicePaid::class]);
    config()->set('billing.gateway', 'fake');
    fpPricing();
    $this->customer = Customer::factory()->create();
    $this->basic = Plan::factory()->priced('100.00')->create(['trial_days' => 0]);
    $this->pro = Plan::factory()->priced('200.00')->create(['trial_days' => 0]);
});

function fpPricing(int $tax = 0, int $discount = 0): void
{
    $client = Mockery::mock(PricingClient::class);
    $client->allows('quote')->andReturnUsing(function (Money $amount) use ($tax, $discount): PriceQuote {
        $off = Money::ofMinor($discount, $amount->currency);
        $vat = Money::ofMinor($tax, $amount->currency);

        return new PriceQuote($amount, $off, $amount->subtract($off), $vat, $amount->subtract($off)->add($vat));
    });
    app()->instance(PricingClient::class, $client);
}

function fpSubscription(Customer $customer, Plan $plan): Subscription
{
    return Subscription::factory()->for($customer)->for($plan, 'plan')
        ->period(CarbonImmutable::parse('2026-06-10 03:00:00'), CarbonImmutable::parse('2026-07-10 03:00:00'))->create();
}

function fpGateway(ChargeResult ...$results): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('charge')->times(count($results))->andReturn(...$results);
    app()->instance(PaymentGateway::class, $gateway);
}

it('creates the immediate proportional initial invoice while keeping access blocked', function (): void {
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code, false));
    $invoice = Invoice::query()->where('subscription_id', $sub->id)->firstOrFail();
    expect($sub->status)->toBe(SubscriptionStatus::PendingPayment)
        ->and($sub->status->grantsAccess())->toBeFalse()
        ->and($sub->current_period_end->toDateTimeString())->toBe('2026-07-10 03:00:00')
        ->and($invoice->kind)->toBe(InvoiceKind::Initial)->and($invoice->subtotal->minorUnits)->toBe(8000)
        ->and($invoice->due_at->eq($this->clock->now()))->toBeTrue();
    expect(fn () => app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->pro->code, false)))
        ->toThrow(DuplicateActiveSubscriptionException::class);
});

it('activates only after the initial invoice is settled and stays anchored to the tenth', function (): void {
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code, false));
    fpGateway(ChargeResult::succeeded('initial-paid'));
    app(BillingService::class)->collect(Invoice::query()->firstOrFail());
    expect($sub->fresh()?->status)->toBe(SubscriptionStatus::Active);
    $this->clock->travelTo(CarbonImmutable::parse('2026-07-10 03:00:00'));
    $renewed = app(SubscriptionService::class)->renew($sub);
    expect($renewed->current_period_end->toDateTimeString())->toBe('2026-08-10 03:00:00');
});

it('keeps the existing free-trial exception but requires payment when the trial ends', function (): void {
    Event::listen(App\Events\Subscriptions\SubscriptionRenewed::class, function ($event): void {
        app(App\Listeners\Billing\IssueInvoiceOnRenewal::class)($event);
    });
    $this->basic->update(['trial_days' => 7]);
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code));
    expect($sub->status)->toBe(SubscriptionStatus::Trialing)->and(Invoice::query()->count())->toBe(0);
    $this->clock->travelTo($sub->current_period_end);
    $sub = app(SubscriptionService::class)->renew($sub);
    expect($sub->status)->toBe(SubscriptionStatus::PendingPayment)->and($sub->status->grantsAccess())->toBeFalse();
    expect(Invoice::query()->firstOrFail()->kind)->toBe(InvoiceKind::Initial);
});

it('aligns proportional periods before, on and after day10 in Sao Paulo', function (string $start, string $end, int $amount): void {
    $period = app(BillingCalendar::class)->firstPeriod(CarbonImmutable::parse($start), App\Enums\BillingInterval::Monthly, Money::ofMinor(3000, Currency::BRL));
    expect($period->end->format('Y-m-d H:i:s'))->toBe($end)->and($period->amount->minorUnits)->toBe($amount);
})->with([
    ['2026-06-05 12:00:00', '2026-06-10 03:00:00', 484],
    ['2026-06-10 12:00:00', '2026-07-10 03:00:00', 3000],
    ['2026-06-16 12:00:00', '2026-07-10 03:00:00', 2400],
]);

it('honors quoted tax and coupon amounts even after pricing and catalogue rules change', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    fpPricing(123, 500);
    $terms = app(SubscriptionService::class)->quotePlanChange($sub, $this->pro->code, 'PROMO');
    fpPricing(999, 0);
    $this->pro->update(['amount' => Money::ofMinor(5000, Currency::BRL)]);
    $data = new ChangePlanData($this->pro->code, quoteId: $terms->quoteId);
    app(SubscriptionService::class)->changePlan($sub, $data);
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->kind)->toBe(InvoiceKind::Adjustment)->and($invoice->discount->minorUnits)->toBe(500)
        ->and($invoice->tax->minorUnits)->toBe(123)->and($invoice->total->equals($terms->quote->total))->toBeTrue()
        ->and($invoice->coupon_code)->toBe('PROMO');
    app(SubscriptionService::class)->changePlan($sub, $data);
    expect(Invoice::query()->count())->toBe(1);
});

it('rejects a quote used for a different subscription, period or downgrade mode', function (): void {
    $sub = fpSubscription($this->customer, $this->pro);
    $terms = app(SubscriptionService::class)->quotePlanChange($sub, $this->basic->code);
    expect(fn () => app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->basic->code, true, $terms->quoteId)))
        ->toThrow(PlanChangeNotAllowedException::class);
    $other = fpSubscription(Customer::factory()->create(), $this->pro);
    expect(fn () => app(SubscriptionService::class)->changePlan($other, new ChangePlanData($this->basic->code, quoteId: $terms->quoteId)))
        ->toThrow(PlanChangeNotAllowedException::class);
    $sub->update(['current_period_end' => $sub->current_period_end->addMonth()]);
    expect(fn () => app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->basic->code, quoteId: $terms->quoteId)))
        ->toThrow(PlanChangeNotAllowedException::class);
});

it('keeps the upgrade during retries then reverts once after exhaustion without affecting prior access', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    $invoice = Invoice::query()->firstOrFail();
    config()->set('billing.dunning.retry_days', [1, 3]);
    fpGateway(ChargeResult::failed(PaymentFailureReason::InsufficientFunds), ChargeResult::failed(PaymentFailureReason::InsufficientFunds));
    app(BillingService::class)->collect($invoice);
    expect($sub->fresh()?->plan_id)->toBe($this->pro->id)->and($sub->fresh()?->status)->toBe(SubscriptionStatus::Active);
    $this->clock->travelTo($invoice->fresh()->next_attempt_at);
    app(BillingService::class)->collect($invoice);
    expect($sub->fresh()?->plan_id)->toBe($this->basic->id)
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Uncollectible)
        ->and($sub->changes()->where('payment_status', 'reverted')->count())->toBe(1);
});

it('reverts at the unpaid deadline, restores a scheduled downgrade and avoids gateway calls', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    $lower = Plan::factory()->priced('50.00')->create();
    $sub->update(['pending_plan_id' => $lower->id]);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    $invoice = Invoice::query()->firstOrFail();
    expect(fn () => app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->basic->code)))
        ->toThrow(PlanChangeNotAllowedException::class);
    $this->clock->travelTo($invoice->payment_deadline_at);
    $this->artisan('invoices:expire-adjustments')->assertSuccessful();
    Queue::assertPushed(ExpireAdjustmentInvoice::class);
    app(BillingService::class)->expireAdjustment($invoice);
    app(BillingService::class)->expireAdjustment($invoice);
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Void)
        ->and($sub->fresh()?->plan_id)->toBe($this->basic->id)
        ->and($sub->fresh()?->pending_plan_id)->toBe($lower->id)
        ->and($invoice->attempts()->count())->toBe(0)
        ->and($sub->changes()->where('type', 'reversion')->count())->toBe(1);
});

it('allows period and multiple completed adjustments in the same period without duplicating a period invoice', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    app(BillingService::class)->issueForPeriod($sub);
    fpGateway(ChargeResult::succeeded('adjustment-paid'));
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    $adjustment = Invoice::query()->where('kind', InvoiceKind::Adjustment)->firstOrFail();
    app(BillingService::class)->collect($adjustment);
    expect($sub->changes()->where('payment_status', 'paid')->count())->toBe(1);
    $higher = Plan::factory()->priced('300.00')->create();
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($higher->code));
    expect(Invoice::query()->count())->toBe(3);
});

it('recovers degraded invoices with current rules once and resumes collection', function (): void {
    $invoice = Invoice::factory()->degraded()->create(['pricing_region' => 'BR-SP', 'coupon_code' => 'PROMO']);
    fpPricing(777, 123);
    $job = new RepriceDegradedInvoice($invoice->uuid);
    $job->handle(app(BillingService::class));
    $job->handle(app(BillingService::class));
    expect($invoice->fresh()?->pricing_degraded)->toBeFalse()->and($invoice->fresh()?->tax->minorUnits)->toBe(777)
        ->and($invoice->fresh()?->discount->minorUnits)->toBe(123)->and($invoice->fresh()?->repriced_at)->not->toBeNull()
        ->and($invoice->fresh()?->pricing_recovery_attempts)->toBe(1);
    Queue::assertPushed(CollectInvoice::class, 1);
});

it('backs off repeated outages and never reprices settled or payment-bound records', function (): void {
    app()->instance(PricingClient::class, new App\Services\Pricing\NullPricingClient);
    $invoice = Invoice::factory()->degraded()->create();
    $service = app(BillingService::class);
    expect($service->repriceDegraded($invoice))->toBeFalse();
    $service->repriceDegraded($invoice);
    expect($invoice->fresh()?->pricing_recovery_attempts)->toBe(1);
    $paid = Invoice::factory()->degraded()->paid()->create();
    expect($service->repriceDegraded($paid))->toBeFalse();
    $this->artisan('invoices:reprice-degraded')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->clock->travelTo($invoice->fresh()->pricing_retry_at);
    $this->artisan('invoices:reprice-degraded')->assertSuccessful();
    Queue::assertPushed(RepriceDegradedInvoice::class, 1);
});

it('releases the subscription lock before a synchronous trial invoice can activate it', function (): void {
    $this->basic->update(['trial_days' => 7]);
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code));
    fpGateway(ChargeResult::succeeded('trial-first-paid'));
    Event::listen(App\Events\Subscriptions\SubscriptionRenewed::class, function ($event): void {
        app(App\Listeners\Billing\IssueInvoiceOnRenewal::class)($event);
        $invoice = Invoice::query()->where('subscription_id', $event->subscription->id)->firstOrFail();
        (new CollectInvoice($invoice->uuid))->handle(app(BillingService::class));
    });
    $this->clock->travelTo($sub->current_period_end);
    app(SubscriptionService::class)->renew($sub);
    expect($sub->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and(Invoice::query()->firstOrFail()->status)->toBe(InvoiceStatus::Paid);
});

it('uses one catalogue snapshot even if a price changes during the pricing call', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    $target = $this->pro;
    $client = Mockery::mock(PricingClient::class);
    $client->shouldReceive('quote')->once()->andReturnUsing(function (Money $amount) use ($target): PriceQuote {
        $target->update(['amount' => Money::ofMinor(5000, Currency::BRL)]);
        $zero = Money::zero($amount->currency);

        return new PriceQuote($amount, $zero, $amount, $zero, $amount);
    });
    app()->instance(PricingClient::class, $client);
    $terms = app(SubscriptionService::class)->quotePlanChange($sub, $target->code);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($target->code, quoteId: $terms->quoteId));
    expect(Invoice::query()->firstOrFail()->kind)->toBe(InvoiceKind::Adjustment)
        ->and(Invoice::query()->firstOrFail()->total->equals($terms->quote->total))->toBeTrue();
});

it('keeps the first invoice recoverable if renewal publication fails after trial conversion', function (): void {
    $this->basic->update(['trial_days' => 7]);
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code));
    Event::listen(App\Events\Subscriptions\SubscriptionRenewed::class, function (): void {
        throw new RuntimeException('simulated post-commit publication failure');
    });
    $this->clock->travelTo($sub->current_period_end);
    expect(fn () => app(SubscriptionService::class)->renew($sub))->toThrow(RuntimeException::class);
    $invoice = Invoice::query()->where('subscription_id', $sub->id)->firstOrFail();
    expect($sub->fresh()?->status)->toBe(SubscriptionStatus::PendingPayment)->and($invoice->kind)->toBe(InvoiceKind::Initial);
    app(SubscriptionService::class)->renew($sub);
    $this->artisan('invoices:collect')->assertSuccessful();
    Queue::assertPushed(CollectInvoice::class, fn (CollectInvoice $job): bool => $job->invoiceUuid === $invoice->uuid);
});

it('rolls back the plan change and quote consumption when invoice persistence fails', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    $terms = app(SubscriptionService::class)->quotePlanChange($sub, $this->pro->code);
    $fault = (object) ['enabled' => true];
    Invoice::creating(function () use ($fault): void {
        if ($fault->enabled) {
            throw new RuntimeException('simulated invoice persistence failure');
        }
    });
    try {
        expect(fn () => app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code, quoteId: $terms->quoteId)))
            ->toThrow(RuntimeException::class);
    } finally {
        $fault->enabled = false;
    }
    expect($sub->fresh()?->plan_id)->toBe($this->basic->id)
        ->and($sub->changes()->count())->toBe(0)
        ->and(App\Models\PlanChangeQuote::query()->firstOrFail()->applied_change_id)->toBeNull();
});

it('never reactivates a past-due regular period by paying only its upgrade difference', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    $sub->update(['status' => SubscriptionStatus::PastDue]);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    fpGateway(ChargeResult::succeeded('upgrade-only'));
    app(BillingService::class)->collect(Invoice::query()->firstOrFail());
    expect($sub->fresh()?->status)->toBe(SubscriptionStatus::PastDue);
});

it('requires a stored preview on public confirmation and rejects a changed coupon', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    actingAsCustomerUser($this->customer, ['subscriptions:read', 'subscriptions:write']);
    $path = '/api/v1/subscriptions/'.$sub->uuid.'/plan';
    $this->putJson($path, ['plan' => $this->pro->code])->assertUnprocessable()->assertJsonValidationErrors('quote_id');
    $quote = $this->postJson($path.'/preview', ['plan' => $this->pro->code, 'coupon' => 'PROMO'])->assertOk()->json('data.quote_id');
    $this->putJson($path, ['plan' => $this->pro->code, 'quote_id' => $quote, 'coupon' => 'OTHER'])
        ->assertUnprocessable()->assertJsonPath('error.context.reason', 'invalid_quote');
    $this->putJson($path, ['plan' => $this->pro->code, 'quote_id' => $quote])->assertOk();
    expect(Invoice::query()->firstOrFail()->coupon_code)->toBe('PROMO');
});

it('does not undo a paid adjustment when its old deadline passes', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    $invoice = Invoice::query()->firstOrFail();
    fpGateway(ChargeResult::succeeded('paid-before-deadline'));
    app(BillingService::class)->collect($invoice);
    $this->clock->travelTo($invoice->payment_deadline_at->addMinute());
    app(BillingService::class)->expireAdjustment($invoice);
    expect($sub->fresh()?->plan_id)->toBe($this->pro->id)->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($sub->changes()->where('type', 'reversion')->count())->toBe(0);
});

it('caps hosted validity at the adjustment deadline and reconciles payment after reversion', function (): void {
    $sub = fpSubscription($this->customer, $this->basic);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code));
    $invoice = Invoice::query()->firstOrFail();
    $this->clock->travelTo($this->clock->now()->addDay());
    config()->set(['billing.gateway' => 'infinitepay', 'infinitepay.handle' => 'merchant-demo',
        'infinitepay.api_key' => 'inchk_test_not_live', 'infinitepay.webhook_url' => 'https://billing.example.test/api/v1/webhooks/infinitepay']);
    Illuminate\Support\Facades\Http::fake(['*/links' => Illuminate\Support\Facades\Http::response(['url' => 'https://checkout.infinitepay.io/test'])]);
    $billing = app(BillingService::class);
    $checkout = $billing->checkout($invoice)->checkout;
    expect($checkout->expires_at->eq($invoice->payment_deadline_at))->toBeTrue();
    $invoice->update(['pricing_degraded' => true]);
    expect($billing->repriceDegraded($invoice))->toBeFalse();
    $invoice->update(['pricing_degraded' => false]);
    $this->clock->travelTo($invoice->payment_deadline_at);
    $billing->expireAdjustment($invoice);
    $payment = new App\DTOs\Billing\VerifiedPayment(new App\DTOs\Billing\PaymentVerification(
        $checkout->order_reference, $checkout->merchant_handle, 'late-transaction', 'late-slug'),
        $invoice->total->minorUnits, $invoice->total->minorUnits, 1, 'pix');
    expect($billing->confirmCheckout($checkout, $payment))->toBeFalse()
        ->and($invoice->fresh()?->status)->toBe(InvoiceStatus::Void)
        ->and($sub->fresh()?->plan_id)->toBe($this->basic->id)
        ->and($invoice->attempts()->count())->toBe(0);
    Event::assertNotDispatched(InvoicePaid::class);
});

it('does not overcharge an upgrade during the proportional initial period', function (): void {
    $sub = app(SubscriptionService::class)->create(new CreateSubscriptionData($this->customer->uuid, $this->basic->code, false));
    $initial = Invoice::query()->firstOrFail();
    fpGateway(ChargeResult::succeeded('partial-initial-paid'));
    app(BillingService::class)->collect($initial);
    // UTC has crossed midnight, but Sao Paulo is still on the same local day.
    $this->clock->travelTo(CarbonImmutable::parse('2026-06-17 01:00:00'));
    $quote = app(SubscriptionService::class)->quotePlanChange($sub->fresh(), $this->pro->code);
    expect($initial->subtotal->minorUnits)->toBe(8000)
        ->and($quote->proration->daysInPeriod)->toBe(30)
        ->and($quote->proration->daysRemaining)->toBe(24)
        ->and($quote->proration->amountDue->minorUnits)->toBe(8000);
    app(SubscriptionService::class)->changePlan($sub, new ChangePlanData($this->pro->code, quoteId: $quote->quoteId));
    expect(Invoice::query()->where('kind', InvoiceKind::Adjustment)->firstOrFail()->subtotal->minorUnits)->toBe(8000);
});
