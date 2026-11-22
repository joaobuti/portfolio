<?php

declare(strict_types=1);

use App\Enums\CheckoutStatus;
use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Events\Billing\InvoicePaid;
use App\Exceptions\Billing\CheckoutException;
use App\Jobs\Billing\VerifyCheckout;
use App\Models\CheckoutNotification;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Services\Billing\BillingService;
use App\Services\Billing\CheckoutNotificationService;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->clock = freezeClockAt('2026-11-22 12:00:00');
    config()->set([
        'billing.gateway' => 'infinitepay', 'infinitepay.handle' => 'merchant-demo',
        'infinitepay.api_key' => 'inchk_test_not_live',
        'infinitepay.webhook_url' => 'https://billing.example.test/api/v1/webhooks/infinitepay',
        'infinitepay.redirect_url' => 'https://billing.example.test/payment-return',
    ]);
    Http::preventStrayRequests();
    ipHttp([
        '*/links' => Http::response(['url' => 'https://checkout.infinitepay.com.br/example']),
        '*/payment_check' => Http::response(ipPaid()),
    ]);
    Queue::fake();
    Event::fake([InvoicePaid::class]);
    $this->invoice = Invoice::factory()->totalling(10000)->create();
});

function ipPaid(array $overrides = []): array
{
    return array_replace(['success' => true, 'paid' => true, 'amount' => 10000, 'paid_amount' => 10000, 'installments' => 1, 'capture_method' => 'pix'], $overrides);
}

function ipOpen(Invoice $invoice): PaymentCheckout
{
    app(BillingService::class)->checkout($invoice);

    return PaymentCheckout::query()->where('invoice_id', $invoice->id)->firstOrFail();
}

function ipSignal(PaymentCheckout $checkout, string $transaction = 'transaction-1'): CheckoutNotification
{
    app(CheckoutNotificationService::class)->accept($checkout->order_reference, $transaction, 'slug-1');

    return CheckoutNotification::query()->where('payment_checkout_id', $checkout->id)->where('transaction_nsu', $transaction)->firstOrFail();
}

it('persists one checkout intent before HTTP and leaves the invoice awaiting payment', function (): void {
    ipHttp(function (Illuminate\Http\Client\Request $request) {
        expect(PaymentCheckout::query()->count())->toBe(1);
        expect($request->header('X-Api-Key'))->toBe(['inchk_test_not_live']);
        expect($request['options']['single_payment'])->toBeTrue()
            ->and($request['options']['valid_until'])->not->toBeEmpty()
            ->and($request['handle'])->toBe('merchant-demo');

        return Http::response(['url' => 'https://checkout.infinitepay.com.br/example']);
    });
    $checkout = ipOpen($this->invoice);
    expect($checkout->status)->toBe(CheckoutStatus::AwaitingPayment)
        ->and($checkout->order_reference)->toStartWith('pb-')
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and($this->invoice->fresh()?->attempt_count)->toBe(0)
        ->and($this->invoice->fresh()?->next_attempt_at)->toBeNull()
        ->and($this->invoice->attempts()->count())->toBe(0);
    Event::assertNotDispatched(InvoicePaid::class);
});

it('returns the existing checkout without creating another provider link', function (): void {
    $first = ipOpen($this->invoice);
    $second = ipOpen($this->invoice->fresh());
    expect($second->id)->toBe($first->id);
    Http::assertSentCount(1);
});

it('does not retry ambiguous provider creation', function (): void {
    $transport = (object) ['calls' => 0];
    ipHttp(function () use ($transport): void {
        $transport->calls++;
        throw new ConnectionException('timeout');
    });
    $first = ipOpen($this->invoice);
    ipOpen($this->invoice->fresh());
    expect($first->status)->toBe(CheckoutStatus::CreationUnknown)
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and($transport->calls)->toBe(1);
});

it('preserves explicit creation rejection without starting card dunning', function (): void {
    ipHttp(['*' => Http::response([], 400)]);
    $checkout = ipOpen($this->invoice);
    expect($checkout->status)->toBe(CheckoutStatus::CreationFailed)
        ->and($this->invoice->fresh()?->attempt_count)->toBe(0);
});

it('rejects provider URL redirects and malformed link responses', function (string $url): void {
    ipHttp(['*' => Http::response(['url' => $url])]);
    expect(ipOpen($this->invoice)->status)->toBe(CheckoutStatus::CreationUnknown);
})->with(['http://checkout.infinitepay.io/x', 'https://evil.example/x', 'https://user@checkout.infinitepay.io/x', 'https://checkout.infinitepay.io:444/x']);

it('blocks unsupported invoice amounts before contacting the provider', function (): void {
    $this->invoice->update(['total' => Money::ofMinor(99, Currency::BRL)]);
    expect(fn (): PaymentCheckout => ipOpen($this->invoice))->toThrow(CheckoutException::class);
    Http::assertNothingSent();
    expect(PaymentCheckout::query()->count())->toBe(0);
});

it('requires configured API key and HTTPS callback before creation', function (): void {
    config()->set('infinitepay.api_key', '');
    expect(fn (): PaymentCheckout => ipOpen($this->invoice))->toThrow(CheckoutException::class);
    Http::assertNothingSent();
});

it('never replaces an expired checkout automatically but accepts late verified payment', function (): void {
    $checkout = ipOpen($this->invoice);
    $this->clock->travelTo($checkout->expires_at->addDay());
    expect(ipOpen($this->invoice)->status)->toBe(CheckoutStatus::Expired);
    $signal = ipSignal($checkout);
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
    Http::assertSentCount(2);
});

it('does not trust a callback and settles only after server confirmation', function (): void {
    $checkout = ipOpen($this->invoice);
    $signal = ipSignal($checkout);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->attempts()->count())->toBe(1)
        ->and($signal->fresh()?->status)->toBe('confirmed');
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/payment_check')
        && $request['handle'] === 'merchant-demo' && $request['order_nsu'] === $checkout->order_reference
        && $request['transaction_nsu'] === 'transaction-1' && $request['slug'] === 'slug-1');
});

it('deduplicates identical signals and repeated successful verification', function (): void {
    $checkout = ipOpen($this->invoice);
    $signal = ipSignal($checkout);
    ipSignal($checkout);
    $service = app(CheckoutNotificationService::class);
    $service->verify($signal->uuid);
    $service->verify($signal->uuid);
    expect(CheckoutNotification::query()->count())->toBe(1)
        ->and($this->invoice->attempts()->count())->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
    Http::assertSentCount(2);
});

it('requires strict provider approval and leaves an unconfirmed payment pending', function (): void {
    ipHttp(['*/links' => Http::response(['url' => 'https://checkout.infinitepay.io/example']),
        '*/payment_check' => Http::response(['success' => false, 'paid' => true])]);
    $signal = ipSignal(ipOpen($this->invoice));
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('pending')
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
});

it('allows installment fees above the invoice amount', function (): void {
    ipHttp(['*/links' => Http::response(['url' => 'https://checkout.infinitepay.io/example']),
        '*/payment_check' => Http::response(ipPaid(['paid_amount' => 11000, 'installments' => 3, 'capture_method' => 'credit_card']))]);
    $signal = ipSignal(ipOpen($this->invoice));
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
});

it('retains mismatched verified payments for reconciliation', function (array $overrides): void {
    ipHttp(['*/links' => Http::response(['url' => 'https://checkout.infinitepay.io/example']),
        '*/payment_check' => Http::response(ipPaid($overrides))]);
    $signal = ipSignal(ipOpen($this->invoice));
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('reconciliation')
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and($this->invoice->attempts()->count())->toBe(0);
})->with([
    [['amount' => 10001]], [['paid_amount' => 9999]], [['capture_method' => 'other']], [['installments' => 2]],
]);

it('prevents the same transaction from settling a second invoice', function (): void {
    $first = ipSignal(ipOpen($this->invoice));
    app(CheckoutNotificationService::class)->verify($first->uuid);
    $other = Invoice::factory()->totalling(10000)->create();
    $second = ipSignal(ipOpen($other));
    app(CheckoutNotificationService::class)->verify($second->uuid);
    expect($other->fresh()?->status)->toBe(InvoiceStatus::Open)
        ->and($second->fresh()?->status)->toBe('reconciliation');
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('recovers verification jobs from the durable inbox', function (): void {
    $signal = ipSignal(ipOpen($this->invoice));
    Illuminate\Support\Facades\Cache::flush();
    Queue::fake();
    $this->artisan('checkouts:verify')->assertSuccessful();
    Queue::assertPushed(VerifyCheckout::class, fn (VerifyCheckout $job): bool => $job->notificationUuid === $signal->uuid);
});

it('retries a provider outage without marking payment failed', function (): void {
    $signal = ipSignal(ipOpen($this->invoice));
    ipHttp(['*' => Http::response([], 503)]);
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('pending')
        ->and($signal->fresh()?->reason)->toBe('provider_unavailable')
        ->and($this->invoice->fresh()?->attempt_count)->toBe(0);
});

function ipHttp(array|Closure $responses): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($responses);
}

it('does not use a synchronous gateway after an invoice has a hosted intent', function (): void {
    ipOpen($this->invoice);
    config()->set('billing.gateway', 'fake');
    expect(fn (): Invoice => app(BillingService::class)->collect($this->invoice))->toThrow(CheckoutException::class);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
});

it('recovers a crash after intent persistence without another creation request', function (): void {
    $checkout = ipOpen($this->invoice);
    $checkout->update(['status' => CheckoutStatus::Creating, 'url' => null]);
    ipHttp([]);
    expect(ipOpen($this->invoice)->status)->toBe(CheckoutStatus::CreationUnknown);
    Http::assertNothingSent();
});

it('never follows provider redirects with an API key', function (): void {
    ipHttp(['*' => Http::response([], 302, ['Location' => 'https://evil.example/steal'])]);
    expect(ipOpen($this->invoice)->status)->toBe(CheckoutStatus::CreationUnknown);
    Http::assertSentCount(1);
});

it('rejects malformed verification fields without settling an invoice', function (array $overrides): void {
    $signal = ipSignal(ipOpen($this->invoice));
    ipHttp(['*' => Http::response(ipPaid($overrides))]);
    app(CheckoutNotificationService::class)->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('pending')
        ->and($signal->fresh()?->reason)->toBe('provider_unavailable')
        ->and($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Open);
})->with([[['success' => 'true']], [['paid' => 1]], [['amount' => '10000']], [['paid_amount' => 10000.5]], [['installments' => null]]]);

it('defers unconfirmed signals and later confirms them without another creation', function (): void {
    $signal = ipSignal(ipOpen($this->invoice));
    ipHttp(['*' => Http::response(['success' => true, 'paid' => false])]);
    $service = app(CheckoutNotificationService::class);
    $service->verify($signal->uuid);
    $service->verify($signal->uuid);
    Http::assertSentCount(1);
    $this->clock->travelTo($signal->fresh()->next_verification_at);
    ipHttp(['*' => Http::response(ipPaid())]);
    $service->verify($signal->uuid);
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid);
    Http::assertSentCount(1);
});

it('retains settlement if inbox completion fails and recovers idempotently', function (): void {
    $signal = ipSignal(ipOpen($this->invoice));
    $fault = (object) ['enabled' => true];
    CheckoutNotification::updating(function (CheckoutNotification $notification) use ($fault): void {
        if ($fault->enabled && $notification->isDirty('status') && $notification->status === 'confirmed') {
            throw new RuntimeException('simulated inbox update failure');
        }
    });
    $service = app(CheckoutNotificationService::class);
    try {
        expect(fn () => $service->verify($signal->uuid))->toThrow(RuntimeException::class);
    } finally {
        $fault->enabled = false;
    }
    expect($this->invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and($signal->fresh()?->status)->toBe('pending');
    $service->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('confirmed')
        ->and($this->invoice->attempts()->count())->toBe(1);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

it('allows authenticated retry of a signal exhausted during provider downtime', function (): void {
    $checkout = ipOpen($this->invoice);
    $signal = ipSignal($checkout);
    $signal->update(['verification_count' => 11]);
    ipHttp(['*' => Http::response([], 503)]);
    $service = app(CheckoutNotificationService::class);
    $service->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('reconciliation');
    $service->accept($checkout->order_reference, $signal->transaction_nsu, $signal->slug);
    expect($signal->fresh()?->status)->toBe('reconciliation');
    $service->accept($checkout->order_reference, $signal->transaction_nsu, $signal->slug, retry: true);
    ipHttp(['*' => Http::response(ipPaid())]);
    $service->verify($signal->uuid);
    expect($signal->fresh()?->status)->toBe('confirmed');
});
