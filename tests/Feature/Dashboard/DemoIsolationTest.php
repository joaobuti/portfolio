<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\CollectInvoice;
use App\Jobs\Billing\ExpireAdjustmentInvoice;
use App\Jobs\Billing\RepriceDegradedInvoice;
use App\Jobs\Subscriptions\ProcessSubscriptionRenewal;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Demo\DemoProvisioner;
use App\Services\Demo\DemoPruner;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function enableIsolatedDemo(): void
{
    app()->instance('env', 'demo');
    config(['demo.enabled' => true, 'demo.ttl_minutes' => 60, 'demo.max_workspaces' => 100,
        'app.debug' => false, 'billing.gateway' => 'fake', 'risk.enabled' => false,
        'infinitepay.handle' => '', 'infinitepay.api_key' => '', 'sanctum.stateful' => ['localhost']]);
    Http::preventStrayRequests();
    test()->withoutMiddleware(ValidateCsrfToken::class);
    test()->withoutMiddleware(Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    test()->withHeader('Origin', 'http://localhost');
}

function demoBrowser(array $identity): void
{
    test()->flushSession();
    Illuminate\Support\Facades\Auth::forgetGuards();
    test()->actingAs($identity['user'], 'web')->withSession(['demo_workspace_id' => $identity['workspace']->id]);
}

beforeEach(function (): void {
    freezeClockAt('2026-11-23 12:00:00');
});

it('keeps all demonstration routes disabled in ordinary environments', function (): void {
    $this->postJson('/api/v1/demo/session', ['profile' => 'customer', 'scenario' => 'active'])->assertNotFound();
    $this->postJson('/api/v1/demo/profile', ['profile' => 'staff'])->assertNotFound();
});

it('refuses schema rollback while synthetic records still depend on the isolation boundary', function (): void {
    enableIsolatedDemo();
    app(DemoProvisioner::class)->create('active', 'customer', null);
    $migration = require database_path('migrations/2026_11_23_000001_add_dashboard_demo_workspaces.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class)
        ->and(Illuminate\Support\Facades\Schema::hasColumn('customers', 'demo_workspace_id'))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(3);
});

it('fails closed for a live gateway, risk worker, secrets, debug or mixed data', function (string $key, mixed $value): void {
    enableIsolatedDemo();
    config([$key => $value]);
    $this->postJson('/api/v1/demo/session', ['profile' => 'customer', 'scenario' => 'active'])->assertStatus(503);
})->with([
    ['billing.gateway', 'infinitepay'], ['risk.enabled', true], ['infinitepay.api_key', 'synthetic-marker'],
    ['infinitepay.handle', 'synthetic-handle'], ['app.debug', true],
]);

it('requires a dedicated database with no normal customer records', function (): void {
    enableIsolatedDemo();
    Customer::factory()->create();
    $this->postJson('/api/v1/demo/session', ['profile' => 'customer', 'scenario' => 'active'])->assertStatus(503);
});

it('provisions profiles without passwords, scopes every lookup and rejects cross-visitor direct access', function (): void {
    enableIsolatedDemo();
    $demo = app(DemoProvisioner::class);
    $a = $demo->create('active', 'staff', null);
    $b = $demo->create('pending', 'customer', null);
    demoBrowser($a);
    $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(3, 'data');
    $foreignCustomer = Customer::query()->where('demo_workspace_id', $b['workspace']->id)->firstOrFail();
    $foreignSubscription = Subscription::query()->where('customer_id', $foreignCustomer->id)->firstOrFail();
    $foreignInvoice = Invoice::query()->where('customer_id', $foreignCustomer->id)->firstOrFail();
    $this->getJson('/api/v1/customers/'.$foreignCustomer->uuid)->assertForbidden();
    $this->getJson('/api/v1/subscriptions/'.$foreignSubscription->uuid)->assertForbidden();
    $this->getJson('/api/v1/invoices/'.$foreignInvoice->uuid)->assertForbidden();
    $this->getJson('/api/v1/invoices?customer='.$foreignCustomer->uuid)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/metrics')->assertForbidden();
    demoBrowser($b);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customers')->assertForbidden();
    expect($a['user']->tokens()->count() + $b['user']->tokens()->count())->toBe(0);
});

it('provides demo entry and role switching bound to the same server workspace', function (): void {
    enableIsolatedDemo();
    $a = $this->postJson('/api/v1/demo/session', ['profile' => 'customer', 'scenario' => 'active']);
    $a->assertOk()->assertJsonPath('data.role', 'customer')->assertJsonPath('demo.simulated', true);
    $this->postJson('/api/v1/demo/profile', ['profile' => 'staff'])->assertOk()
        ->assertJsonPath('data.role', 'staff')->assertJsonPath('demo.workspace', $a->json('demo.workspace'));
    $this->postJson('/api/v1/demo/profile', ['profile' => 'administrator'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/tokens', [])->assertNotFound();
    $this->postJson('/api/v1/auth/session', [])->assertNotFound();
    $this->postJson('/api/v1/webhooks/infinitepay', [])->assertNotFound();
});

it('expires old generations on reset while preserving other visitors and rejecting old sessions', function (): void {
    enableIsolatedDemo();
    $demo = app(DemoProvisioner::class);
    $a = $demo->create('active', 'customer', null);
    $b = $demo->create('active', 'customer', null);
    $new = $demo->create('pending', 'customer', $a['workspace']->id);
    demoBrowser($a);
    $this->getJson('/api/v1/subscriptions')->assertUnauthorized();
    demoBrowser($b);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(1, 'data');
    demoBrowser($new);
    $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonPath('data.0.status.value', 'pending_payment');
    expect(app(DemoPruner::class)->prune())->toBe(1)
        ->and(DemoWorkspace::query()->count())->toBe(2)
        ->and(User::query()->where('demo_workspace_id', $a['workspace']->id)->count())->toBe(0);
});

it('enforces absolute expiry and a hard workspace cap', function (): void {
    enableIsolatedDemo();
    config(['demo.max_workspaces' => 1]);
    $identity = app(DemoProvisioner::class)->create('active', 'customer', null);
    demoBrowser($identity);
    $this->postJson('/api/v1/demo/session', ['profile' => 'customer', 'scenario' => 'pending'])->assertStatus(429);
    freezeClockAt('2026-11-23 13:01:00');
    $this->getJson('/api/v1/subscriptions')->assertUnauthorized();
    $this->artisan('demo:prune')->assertSuccessful();
    expect(DemoWorkspace::query()->count())->toBe(0);
});

it('simulates pending then approval without a real payment or duplicate settlement', function (): void {
    enableIsolatedDemo();
    $identity = app(DemoProvisioner::class)->create('pending', 'customer', null);
    demoBrowser($identity);
    $invoice = Invoice::query()->where('customer_id', $identity['user']->customer_id)->firstOrFail();
    $url = '/api/v1/demo/invoices/'.$invoice->uuid.'/payment';
    $this->postJson($url, ['outcome' => 'pending'])->assertOk()->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.simulation.payment_status', 'pending');
    $this->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.status.grants_access', false);
    $this->postJson($url, ['outcome' => 'approved'])->assertOk()->assertJsonPath('data.status', 'paid');
    $this->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.status.grants_access', true);
    $this->postJson($url, ['outcome' => 'approved'])->assertOk()->assertJsonPath('data.status', 'paid');
    expect($invoice->attempts()->count())->toBe(1);
    Http::assertNothingSent();
});

it('records simulated declines and respects the existing retry schedule', function (): void {
    enableIsolatedDemo();
    $identity = app(DemoProvisioner::class)->create('pending', 'customer', null);
    demoBrowser($identity);
    $invoice = Invoice::query()->where('customer_id', $identity['user']->customer_id)->firstOrFail();
    $url = '/api/v1/demo/invoices/'.$invoice->uuid.'/payment';
    $this->postJson($url, ['outcome' => 'declined'])->assertOk()->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.simulation.payment_status', 'declined');
    expect($invoice->fresh()?->next_attempt_at?->isFuture())->toBeTrue();
    $this->postJson($url, ['outcome' => 'approved'])->assertUnprocessable();
    $this->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.status.grants_access', false);
    Http::assertNothingSent();
});

it('keeps initial settlement independent from upgrade adjustments and supports a scheduled downgrade', function (): void {
    enableIsolatedDemo();
    $identity = app(DemoProvisioner::class)->create('pending', 'customer', null);
    demoBrowser($identity);
    $subscription = Subscription::query()->where('customer_id', $identity['user']->customer_id)->firstOrFail();
    $initial = Invoice::query()->where('subscription_id', $subscription->id)->firstOrFail();
    $payment = '/api/v1/demo/invoices/';
    $this->postJson($payment.$initial->uuid.'/payment', ['outcome' => 'approved'])->assertOk();
    $planUrl = '/api/v1/subscriptions/'.$subscription->uuid.'/plan';
    $quote = $this->postJson($planUrl.'/preview', ['plan' => 'demo-business'])->assertOk()->json('data.quote_id');
    $this->putJson($planUrl, ['plan' => 'demo-business', 'quote_id' => $quote])->assertOk();
    $adjustment = Invoice::query()->where('subscription_id', $subscription->id)->where('kind', 'adjustment')->firstOrFail();
    $this->postJson($payment.$adjustment->uuid.'/payment', ['outcome' => 'pending'])->assertOk();
    $this->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.status.grants_access', true);
    expect($initial->fresh()?->status)->toBe(InvoiceStatus::Paid);
    $this->postJson($payment.$adjustment->uuid.'/payment', ['outcome' => 'approved'])->assertOk();
    $quote = $this->postJson($planUrl.'/preview', ['plan' => 'demo-essential'])->assertOk()->json('data.quote_id');
    $this->putJson($planUrl, ['plan' => 'demo-essential', 'quote_id' => $quote])->assertOk();
    $this->getJson('/api/v1/subscriptions')->assertJsonPath('data.0.plan.code', 'demo-business')
        ->assertJsonPath('data.0.next_charge.plan', 'demo-essential')
        ->assertJsonPath('data.0.next_charge.subtotal.amount', 10000);
    $this->deleteJson('/api/v1/subscriptions/'.$subscription->uuid)->assertOk()
        ->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.next_charge', null);
    app(DemoProvisioner::class)->create('active', 'customer', $identity['workspace']->id);
    expect(app(DemoPruner::class)->prune())->toBe(1);
    Http::assertNothingSent();
});

it('blocks staff simulation and real checkout and ignores demo rows in automatic jobs and sweeps', function (): void {
    enableIsolatedDemo();
    $identity = app(DemoProvisioner::class)->create('pending', 'staff', null);
    demoBrowser($identity);
    $invoice = Invoice::query()->firstOrFail();
    $this->postJson('/api/v1/demo/invoices/'.$invoice->uuid.'/payment', ['outcome' => 'approved'])->assertForbidden();
    $this->postJson('/api/v1/invoices/'.$invoice->uuid.'/checkout', [])->assertNotFound();
    Queue::fake();
    app()->call([new CollectInvoice($invoice->uuid), 'handle']);
    app()->call([new RepriceDegradedInvoice($invoice->uuid), 'handle']);
    app()->call([new ExpireAdjustmentInvoice($invoice->uuid), 'handle']);
    $subscription = Subscription::query()->firstOrFail();
    app()->call([new ProcessSubscriptionRenewal($subscription->uuid), 'handle']);
    $this->artisan('invoices:collect')->assertSuccessful();
    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Open)->and($invoice->attempts()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
