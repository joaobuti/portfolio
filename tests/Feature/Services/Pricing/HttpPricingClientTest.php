<?php

declare(strict_types=1);

use App\Enums\CouponRejection;
use App\Enums\Currency;
use App\Services\Pricing\CircuitBreaker;
use App\Services\Pricing\HttpPricingClient;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The client is tested against a faked transport rather than the real service.
 * What is under test is our behaviour when the dependency misbehaves — and a
 * dependency that is up cannot demonstrate a timeout.
 *
 * The contract these fakes encode is verified against the real service by
 * services/pricing's own suite; if the two ever drift, that is what an
 * integration test in CI would catch.
 */
beforeEach(function (): void {
    Cache::flush();

    config()->set('pricing.enabled', true);
    config()->set('pricing.base_uri', 'http://pricing.test');
    config()->set('pricing.retries', 0);
    config()->set('pricing.breaker.failure_threshold', 2);
    config()->set('pricing.breaker.open_seconds', 30);

    $this->client = app(HttpPricingClient::class);
    $this->breaker = app(CircuitBreaker::class);
});

function pricingResponse(array $overrides = []): array
{
    return array_merge([
        'currency' => 'BRL',
        'subtotalMinorUnits' => 10_000,
        'discountMinorUnits' => 1000,
        'taxableMinorUnits' => 9000,
        'taxMinorUnits' => 810,
        'totalMinorUnits' => 9810,
        'lines' => [
            ['type' => 'coupon', 'code' => 'WELCOME10', 'description' => '10% off', 'amountMinorUnits' => -1000],
            ['type' => 'tax', 'code' => 'BR-SP-ISS', 'description' => 'ISS São Paulo', 'amountMinorUnits' => 810],
        ],
        'coupon' => ['code' => 'WELCOME10', 'applied' => true],
        'taxRegion' => 'BR-SP-ISS',
    ], $overrides);
}

describe('the happy path', function (): void {
    it('maps a quote onto Money in the requested currency', function (): void {
        Http::fake(['*' => Http::response(pricingResponse())]);

        $quote = $this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP', 'WELCOME10');

        expect($quote->isDegraded())->toBeFalse()
            ->and($quote->total->minorUnits)->toBe(9810)
            ->and($quote->total->currency)->toBe(Currency::BRL)
            ->and($quote->tax->minorUnits)->toBe(810)
            ->and($quote->lines)->toHaveCount(2)
            ->and($quote->coupon?->applied)->toBeTrue()
            ->and($quote->reconciles())->toBeTrue();
    });

    it('reports a rejected coupon without degrading the quote', function (): void {
        Http::fake(['*' => Http::response(pricingResponse([
            'discountMinorUnits' => 0,
            'taxableMinorUnits' => 10_000,
            'taxMinorUnits' => 900,
            'totalMinorUnits' => 10_900,
            'lines' => [],
            'coupon' => ['code' => 'NOPE', 'applied' => false, 'reason' => 'unknown'],
        ]))]);

        $quote = $this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP', 'NOPE');

        // A typo is not a service failure. If it degraded here, a customer
        // guessing coupon codes could open the circuit for everyone.
        expect($quote->isDegraded())->toBeFalse()
            ->and($quote->coupon?->reason)->toBe(CouponRejection::Unknown);
    });

    it('resets the breaker after a success', function (): void {
        Http::fake(['*' => Http::response(pricingResponse())]);
        $this->breaker->recordFailure();

        $this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP');

        expect($this->breaker->failureCount())->toBe(0);
    });
});

describe('degrading', function (): void {
    it('degrades when malformed response arithmetic overflows', function (): void {
        Http::fake(['*' => Http::response(pricingResponse([
            'subtotalMinorUnits' => PHP_INT_MAX,
            'discountMinorUnits' => 0,
            'taxableMinorUnits' => PHP_INT_MAX,
            'taxMinorUnits' => 1,
            'totalMinorUnits' => PHP_INT_MAX,
        ]))]);
        expect($this->client->quote(Money::ofMinor(PHP_INT_MAX, Currency::BRL), 'BR')->isDegraded())->toBeTrue();
    });

    it('rejects a reconciled quote for a different subtotal', function (): void {
        Http::fake(['*' => Http::response(pricingResponse([
            'subtotalMinorUnits' => 1, 'discountMinorUnits' => 0,
            'taxableMinorUnits' => 1, 'taxMinorUnits' => 0, 'totalMinorUnits' => 1,
        ]))]);
        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR')->isDegraded())->toBeTrue();
    });

    it('rejects an inconsistent taxable amount', function (): void {
        Http::fake(['*' => Http::response(pricingResponse(['taxableMinorUnits' => 1]))]);
        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR')->isDegraded())->toBeTrue();
    });

    it('does not retry an HTTP error even when connection retries are enabled', function (int $status): void {
        config()->set('pricing.retries', 2);
        Http::fake(['*' => Http::response([], $status)]);
        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR')->isDegraded())->toBeTrue();
        Http::assertSentCount(1);
    })->with([422, 503]);

    it('degrades on a connection failure', function (): void {
        Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timed out'));

        $quote = $this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP');

        expect($quote->isDegraded())->toBeTrue()
            ->and($quote->total->minorUnits)->toBe(10_000)
            ->and($quote->tax->isZero())->toBeTrue()
            ->and($quote->reconciles())->toBeTrue();
    });

    it('degrades on a server error and counts it against the breaker', function (): void {
        Http::fake(['*' => Http::response(status: 503)]);

        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP')->isDegraded())->toBeTrue()
            ->and($this->breaker->failureCount())->toBe(1);
    });

    it('degrades on a malformed response rather than trusting it', function (): void {
        Http::fake(['*' => Http::response(pricingResponse(['totalMinorUnits' => 'not a number']))]);

        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP')->isDegraded())->toBeTrue();
    });

    it('refuses a quote whose numbers do not add up', function (): void {
        // Every field is a valid integer; they simply do not reconcile. This is
        // the failure that would otherwise become a charge nobody can explain.
        Http::fake(['*' => Http::response(pricingResponse(['totalMinorUnits' => 12_345]))]);

        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP')->isDegraded())->toBeTrue();
    });

    it('refuses a quote answered in the wrong currency', function (): void {
        Http::fake(['*' => Http::response(pricingResponse(['currency' => 'USD']))]);

        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP')->isDegraded())->toBeTrue();
    });

    it('does not count our own bad request against the breaker', function (): void {
        // 422 means the service is healthy and we sent it nonsense. Counting it
        // would let one bad deploy of ours black out a service that never
        // faltered.
        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_request']], 422)]);

        expect($this->client->quote(Money::ofMinor(10_000, Currency::BRL), 'BR-SP')->isDegraded())->toBeTrue()
            ->and($this->breaker->failureCount())->toBe(0);
    });
});

describe('the circuit breaker', function (): void {
    it('opens after the threshold and stops calling the network', function (): void {
        Http::fake(['*' => Http::response(status: 500)]);

        $this->client->quote(Money::ofMinor(100, Currency::BRL), 'BR');
        $this->client->quote(Money::ofMinor(100, Currency::BRL), 'BR');

        expect($this->breaker->isOpen())->toBeTrue();

        Http::fake(['*' => Http::response(pricingResponse())]);

        // Still degraded, and no request was sent: the point of the breaker is
        // that a sick dependency stops costing us the full timeout every time.
        expect($this->client->quote(Money::ofMinor(100, Currency::BRL), 'BR')->isDegraded())->toBeTrue();
        Http::assertNothingSent();
    });
});
