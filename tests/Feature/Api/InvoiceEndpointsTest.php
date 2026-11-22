<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\VerifyCheckout;
use App\Models\CheckoutNotification;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    freezeClockAt('2026-11-22 12:00:00');
    config()->set(['billing.gateway' => 'infinitepay', 'infinitepay.handle' => 'merchant-demo',
        'infinitepay.api_key' => 'inchk_test_not_live',
        'infinitepay.webhook_url' => 'https://billing.example.test/api/v1/webhooks/infinitepay']);
    Http::preventStrayRequests();
    Http::fake(['*/links' => Http::response(['url' => 'https://checkout.infinitepay.com.br/example'])]);
    Queue::fake();
    $this->customer = Customer::factory()->create();
    $this->subscription = Subscription::factory()->for($this->customer)->create();
    $this->invoice = Invoice::factory()->for($this->customer)->for($this->subscription)->create();
});

it('requires authentication and an invoice scope', function (): void {
    $url = '/api/v1/invoices/'.$this->invoice->uuid;
    $this->getJson($url)->assertUnauthorized();
    actingAsCustomerUser($this->customer, ['subscriptions:read']);
    $this->getJson($url)->assertForbidden();
});

it('lists and reads owned invoice amounts without exposing merchant configuration', function (): void {
    actingAsCustomerUser($this->customer, ['invoices:read']);
    $this->getJson('/api/v1/subscriptions/'.$this->subscription->uuid.'/invoices')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->invoice->uuid);
    $this->getJson('/api/v1/invoices/'.$this->invoice->uuid)->assertOk()
        ->assertJsonPath('data.total.amount', 10000)->assertJsonMissingPath('data.merchant_handle')
        ->assertJsonMissingPath('data.api_key');
});

it('rejects another customer invoice and subscription listing', function (): void {
    actingAsCustomerUser(Customer::factory()->create(), ['invoices:read', 'invoices:write']);
    $this->getJson('/api/v1/invoices/'.$this->invoice->uuid)->assertForbidden();
    $this->getJson('/api/v1/subscriptions/'.$this->subscription->uuid.'/invoices')->assertForbidden();
    $this->postJson('/api/v1/invoices/'.$this->invoice->uuid.'/checkout')->assertForbidden();
});

it('does not let a read-only token create a checkout', function (): void {
    actingAsCustomerUser($this->customer, ['invoices:read']);
    $this->postJson('/api/v1/invoices/'.$this->invoice->uuid.'/checkout')->assertForbidden();
    Http::assertNothingSent();
});

it('returns a pending checkout and reuses it on repeated requests', function (): void {
    actingAsCustomerUser($this->customer, ['invoices:write']);
    $url = '/api/v1/invoices/'.$this->invoice->uuid.'/checkout';
    $this->postJson($url)->assertAccepted()->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.checkout.status', 'awaiting_payment')
        ->assertJsonPath('data.checkout.url', 'https://checkout.infinitepay.com.br/example')
        ->assertJsonMissingPath('data.checkout.merchant_handle');
    $this->postJson($url)->assertAccepted();
    Http::assertSentCount(1);
});

it('does not accidentally use the fake gateway for the checkout endpoint', function (): void {
    config()->set('billing.gateway', 'fake');
    actingAsCustomerUser($this->customer, ['invoices:write']);
    $this->postJson('/api/v1/invoices/'.$this->invoice->uuid.'/checkout')->assertStatus(503);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
    Http::assertNothingSent();
});

it('accepts and persists a public webhook without trusting its payment fields', function (): void {
    app(BillingService::class)->checkout($this->invoice);
    $checkout = PaymentCheckout::query()->firstOrFail();
    $this->postJson('/api/v1/webhooks/infinitepay', [
        'order_nsu' => $checkout->order_reference, 'transaction_nsu' => 'transaction-1', 'invoice_slug' => 'slug-1',
        'paid' => true, 'amount' => 1, 'handle' => 'attacker',
    ])->assertAccepted();
    expect(CheckoutNotification::query()->count())->toBe(1)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
    Queue::assertPushed(VerifyCheckout::class);
    Http::assertSentCount(1);
});

it('ignores notifications belonging to other projects using the same merchant', function (): void {
    $this->postJson('/api/v1/webhooks/infinitepay', [
        'order_nsu' => 'jboot-other-order', 'transaction_nsu' => 'transaction-1', 'invoice_slug' => 'slug-1',
    ])->assertAccepted();
    expect(CheckoutNotification::query()->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('validates identifiers and bounds unsigned webhook body size', function (): void {
    $this->postJson('/api/v1/webhooks/infinitepay', [])->assertUnprocessable();
    $this->postJson('/api/v1/webhooks/infinitepay', ['ignored' => str_repeat('x', 17000)])->assertStatus(413);
});

it('accepts authenticated redirect identifiers through the same verification inbox', function (): void {
    app(BillingService::class)->checkout($this->invoice);
    actingAsCustomerUser($this->customer, ['invoices:write']);
    $this->postJson('/api/v1/invoices/'.$this->invoice->uuid.'/payment-check', [
        'transaction_nsu' => 'transaction-1', 'slug' => 'slug-1',
    ])->assertAccepted();
    expect(CheckoutNotification::query()->count())->toBe(1)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
});

it('hides expired and paid checkout URLs on reads without requiring a write', function (): void {
    app(BillingService::class)->checkout($this->invoice);
    $checkout = PaymentCheckout::query()->firstOrFail();
    $checkout->update(['expires_at' => app(App\Support\Clock::class)->now()->subSecond()]);
    actingAsCustomerUser($this->customer, ['invoices:read']);
    $url = '/api/v1/invoices/'.$this->invoice->uuid;
    $this->getJson($url)->assertOk()->assertJsonPath('data.checkout.status', 'expired')
        ->assertJsonPath('data.checkout.url', null);
    $checkout->update(['expires_at' => app(App\Support\Clock::class)->now()->addDay()]);
    $this->invoice->update(['status' => InvoiceStatus::Paid]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.checkout.url', null);
});

it('returns a contract-compatible null checkout when settling zero locally', function (): void {
    $this->invoice->update(['total' => App\Support\Money::zero(App\Enums\Currency::BRL)]);
    actingAsCustomerUser($this->customer, ['invoices:write']);
    $this->postJson('/api/v1/invoices/'.$this->invoice->uuid.'/checkout')
        ->assertAccepted()->assertJsonPath('data.status', 'paid')
        ->assertJsonStructure(['data' => ['checkout']])->assertJsonPath('data.checkout', null);
    Http::assertNothingSent();
    expect(PaymentCheckout::query()->count())->toBe(0);
});
