<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenAbility;
use App\Models\CheckoutNotification;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Services\Observability\OperationalMetrics;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->clock = freezeClockAt('2026-11-22 12:00:00');
    Http::preventStrayRequests();
});

it('requires authentication and the metrics scope even for staff', function (): void {
    $this->getJson('/api/v1/metrics')->assertUnauthorized();
    actingAsStaff(['invoices:read']);
    $this->getJson('/api/v1/metrics')->assertForbidden();
});

it('denies customers even when a credential explicitly grants the metrics scope', function (array $abilities): void {
    actingAsCustomerUser(Customer::factory()->create(), $abilities);
    $this->getJson('/api/v1/metrics')->assertForbidden();
})->with([[['metrics:read']], [['*']]]);

it('does not grant customers the monitoring ability at token issuance', function (): void {
    $user = actingAsCustomerUser(Customer::factory()->create());
    expect($user->role->defaultAbilities())->not->toContain(TokenAbility::MetricsRead);
    $this->postJson('/api/v1/auth/tokens', ['email' => $user->email,
        'password' => Database\Factories\UserFactory::PASSWORD, 'abilities' => ['metrics:read']])
        ->assertCreated()->assertJsonPath('data.abilities', []);
});

it('publishes an empty database as bounded zero gauges with explicit types', function (): void {
    actingAsStaff(['metrics:read']);
    $response = $this->get('/api/v1/metrics')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    $text = $response->getContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect($text)->toEndWith("\n")
        ->toContain('# TYPE portfolio_billing_invoices gauge',
            'portfolio_billing_invoices{status="open"} 0',
            'portfolio_billing_checkout_notifications{status="reconciliation"} 0',
            'portfolio_billing_oldest_pending_checkout_notification_age_seconds 0',
            'portfolio_billing_snapshot_timestamp_seconds '.$this->clock->now()->getTimestamp());
    Http::assertNothingSent();
});

it('aggregates current states and uses due collection eligibility without hydrating invoices', function (): void {
    $now = $this->clock->now();
    Invoice::factory()->create(['next_attempt_at' => $now]);
    Invoice::factory()->create(['next_attempt_at' => $now->addDay()]);
    Invoice::factory()->create(['next_attempt_at' => null]);
    Invoice::factory()->degraded()->create(['next_attempt_at' => $now]);
    Invoice::factory()->paid()->create(['next_attempt_at' => $now]);
    Subscription::factory()->status(SubscriptionStatus::PastDue)->create();
    Subscription::factory()->onTrial()->create();
    $body = app(OperationalMetrics::class)->render();
    expect($body)->toContain('portfolio_billing_invoices{status="open"} 4',
        'portfolio_billing_invoices{status="paid"} 1',
        'portfolio_billing_collection_due_invoices 1',
        'portfolio_billing_degraded_open_invoices 1',
        'portfolio_billing_subscriptions{status="past_due"} 1',
        'portfolio_billing_subscriptions{status="trialing"} 1');
    expect(Invoice::query()->where('status', InvoiceStatus::Paid)->count())->toBe(1);
    Http::assertNothingSent();
});

it('reports effective checkout expiry without changing stored statuses or exposing identifiers', function (): void {
    $expired = metricsCheckout(CheckoutStatus::AwaitingPayment, true);
    metricsCheckout(CheckoutStatus::AwaitingPayment);
    metricsCheckout(CheckoutStatus::CreationUnknown);
    $body = app(OperationalMetrics::class)->render();
    expect($body)->toContain('portfolio_billing_checkouts{status="expired"} 1',
        'portfolio_billing_checkouts{status="awaiting_payment"} 1',
        'portfolio_billing_checkouts{status="creation_unknown"} 1')
        ->not->toContain($expired->uuid, $expired->order_reference, $expired->merchant_handle, 'example.test');
    expect($expired->fresh()?->status)->toBe(CheckoutStatus::AwaitingPayment);
});

it('reports due verification work and oldest pending age without counting terminal signals', function (): void {
    $checkout = metricsCheckout(CheckoutStatus::AwaitingPayment);
    metricsSignal($checkout, 'pending', -120, -1);
    metricsSignal($checkout, 'pending', -60, 60);
    metricsSignal($checkout, 'reconciliation', -600, null);
    metricsSignal($checkout, 'confirmed', -900, null);
    $body = app(OperationalMetrics::class)->render();
    expect($body)->toContain('portfolio_billing_checkout_notifications{status="pending"} 2',
        'portfolio_billing_checkout_notifications{status="reconciliation"} 1',
        'portfolio_billing_checkout_notifications{status="confirmed"} 1',
        'portfolio_billing_checkout_verifications_due 1',
        'portfolio_billing_oldest_pending_checkout_notification_age_seconds 120');
});

it('clamps pending ages when timestamps are ahead of the application clock', function (): void {
    metricsSignal(metricsCheckout(CheckoutStatus::Creating), 'pending', 60, 60);
    expect(app(OperationalMetrics::class)->render())->toContain('portfolio_billing_oldest_pending_checkout_notification_age_seconds 0');
});

it('reuses a short snapshot without new SQL and refreshes it after expiry', function (): void {
    config()->set('cache.default', 'array');
    actingAsStaff(['metrics:read']);
    $first = $this->get('/api/v1/metrics')->assertOk()->getContent();
    Invoice::factory()->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(OperationalMetrics::class)->render())->toBe($first)
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    $this->clock->travelTo($this->clock->now()->addSeconds(16));
    // Move the framework clock too, so the array store expires the snapshot.
    Illuminate\Support\Carbon::setTestNow($this->clock->now());
    Carbon\CarbonImmutable::setTestNow($this->clock->now());
    $fresh = $this->get('/api/v1/metrics')->assertOk()->getContent();
    expect($fresh)->not->toBe($first)->toContain('portfolio_billing_invoices{status="open"} 1');
});

function metricsCheckout(CheckoutStatus $status, bool $expired = false): PaymentCheckout
{
    $invoice = Invoice::factory()->create();
    $id = (string) Str::uuid();

    return PaymentCheckout::query()->create(['uuid' => $id, 'invoice_id' => $invoice->id, 'status' => $status,
        'order_reference' => 'pb-'.$id, 'merchant_handle' => 'private-merchant',
        'amount_cents' => 10000, 'currency' => 'BRL', 'webhook_url' => 'https://example.test/webhook',
        'expires_at' => app(Clock::class)->now()->addSeconds($expired ? -1 : 60)]);
}

function metricsSignal(PaymentCheckout $checkout, string $status, int $createdOffset, ?int $dueOffset): void
{
    $id = (string) Str::uuid();
    CheckoutNotification::query()->create(['payment_checkout_id' => $checkout->id,
        'transaction_nsu' => $id, 'slug' => 'private-slug', 'fingerprint' => hash('sha256', $id), 'status' => $status,
        'created_at' => app(Clock::class)->now()->addSeconds($createdOffset),
        'next_verification_at' => $dueOffset === null ? null : app(Clock::class)->now()->addSeconds($dueOffset)]);
}

it('fails the scrape rather than publishing zeros when aggregation is unavailable', function (): void {
    actingAsStaff(['metrics:read']);
    config()->set('app.debug', false);
    DB::partialMock()->shouldReceive('table')->with('invoices')->once()->andThrow(new RuntimeException('simulated outage'));
    $response = $this->get('/api/v1/metrics')->assertStatus(500);
    expect($response->getContent())->not->toContain('portfolio_billing_invoices{status="open"} 0');
});
