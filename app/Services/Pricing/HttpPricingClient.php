<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTOs\Pricing\CouponOutcome;
use App\DTOs\Pricing\PriceQuote;
use App\DTOs\Pricing\QuoteLine;
use App\Enums\CouponRejection;
use App\Enums\Currency;
use App\Enums\PriceLineType;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Talks to the Node pricing service over HTTP.
 *
 * Everything defensive in this class exists because the call is synchronous and
 * in the path of a customer pressing a button. In order of how often they
 * actually save you:
 *
 * 1. **The breaker.** Skips the network entirely while the dependency is known
 *    to be down. This is the one that prevents an outage.
 * 2. **The timeouts.** Shorter than the service's own, so we control the
 *    fallback rather than inheriting whatever it decides to do.
 * 3. **The reconciliation check.** A response whose numbers do not add up is
 *    treated as a failure, not as a total. Charging an amount no rule can
 *    explain is worse than not charging tax.
 *
 * ## Failures that trip the breaker, and failures that do not
 *
 * A timeout or a 5xx means the dependency is unhealthy: counted, and the
 * circuit eventually opens. A 4xx means the dependency is perfectly healthy
 * and is telling us our request is malformed — that is our bug, so it is
 * logged loudly and the breaker is left closed. Counting it would let one bad
 * deploy of ours black out a service that never faltered.
 */
final readonly class HttpPricingClient implements PricingClient
{
    public function __construct(private CircuitBreaker $breaker) {}

    public function quote(Money $amount, string $region, ?string $couponCode = null): PriceQuote
    {
        if ($this->breaker->isOpen()) {
            return PriceQuote::degraded($amount, $couponCode);
        }

        try {
            $response = $this->send($amount, $region, $couponCode);
        } catch (ConnectionException $exception) {
            return $this->unhealthy($amount, $couponCode, 'connection', [
                'message' => $exception->getMessage(),
            ]);
        }

        if ($response->serverError()) {
            return $this->unhealthy($amount, $couponCode, 'server_error', [
                'status' => $response->status(),
            ]);
        }

        if ($response->clientError()) {
            // The dependency is fine; we sent it something it could not price.
            $this->breaker->recordSuccess();

            Log::error('Pricing service rejected the request.', [
                'status' => $response->status(),
                'body' => $response->json(),
                'region' => $region,
            ]);

            return PriceQuote::degraded($amount, $couponCode);
        }

        try {
            $quote = $this->toQuote($response, $amount);
            if (! $quote->reconciles()) {
                return $this->unhealthy($amount, $couponCode, 'quote_does_not_reconcile', [
                    'subtotal' => $quote->subtotal->minorUnits,
                    'total' => $quote->total->minorUnits,
                ]);
            }
        } catch (Throwable $exception) {
            return $this->unhealthy($amount, $couponCode, 'malformed_response', [
                'message' => $exception->getMessage(),
            ]);
        }

        $this->breaker->recordSuccess();

        return $quote;
    }

    private function send(Money $amount, string $region, ?string $couponCode): Response
    {
        /** @var array{base_uri: string, timeout: float, connect_timeout: float, retries: int, retry_delay_ms: int} $config */
        $config = config('pricing');

        return Http::baseUrl($config['base_uri'])
            ->timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            // Retry only connection failures. Retrying a 5xx would double the
            // load on a service that is already struggling.
            ->retry(
                $config['retries'] + 1,
                $config['retry_delay_ms'],
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            )
            ->acceptJson()
            ->asJson()
            ->post('/v1/quote', array_filter([
                'amountMinorUnits' => $amount->minorUnits,
                'currency' => $amount->currency->value,
                'region' => $region,
                'couponCode' => $couponCode,
            ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Record a dependency failure and hand back the fallback.
     *
     * @param  array<string, mixed>  $context
     */
    private function unhealthy(
        Money $amount,
        ?string $couponCode,
        string $reason,
        array $context = [],
    ): PriceQuote {
        $this->breaker->recordFailure();

        Log::warning('Pricing service call failed; falling back to an untaxed quote.', [
            'reason' => $reason,
            'consecutive_failures' => $this->breaker->failureCount(),
            ...$context,
        ]);

        return PriceQuote::degraded($amount, $couponCode);
    }

    /**
     * Map the wire response onto the domain, rebuilding every amount as Money
     * in the currency we asked about.
     *
     * The currency is asserted rather than read from the response: if the
     * service ever answered in a different currency, silently trusting it
     * would produce a Money that is wrong by a factor of five and looks fine.
     */
    private function toQuote(Response $response, Money $amount): PriceQuote
    {
        /** @var array<string, mixed> $body */
        $body = $response->json();

        $currency = Currency::from($this->string($body, 'currency'));

        if ($currency !== $amount->currency) {
            throw new RuntimeException(sprintf(
                'Pricing service answered in [%s] for a [%s] amount.',
                $currency->value,
                $amount->currency->value,
            ));
        }

        if ($this->integer($body, 'subtotalMinorUnits') !== $amount->minorUnits) {
            throw new RuntimeException('Pricing response subtotal differs from the requested amount.');
        }

        if ($this->integer($body, 'subtotalMinorUnits') - $this->integer($body, 'discountMinorUnits')
            !== $this->integer($body, 'taxableMinorUnits')) {
            throw new RuntimeException('Pricing response taxable amount does not reconcile.');
        }

        return new PriceQuote(
            subtotal: $this->money($body, 'subtotalMinorUnits', $currency),
            discount: $this->money($body, 'discountMinorUnits', $currency),
            taxable: $this->money($body, 'taxableMinorUnits', $currency),
            tax: $this->money($body, 'taxMinorUnits', $currency),
            total: $this->money($body, 'totalMinorUnits', $currency),
            lines: $this->lines($body, $currency),
            coupon: $this->coupon($body),
            taxRegion: is_string($body['taxRegion'] ?? null) ? $body['taxRegion'] : null,
            degraded: false,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<QuoteLine>
     */
    private function lines(array $body, Currency $currency): array
    {
        $raw = $body['lines'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $lines = [];

        foreach ($raw as $line) {
            if (! is_array($line)) {
                continue;
            }

            /** @var array<string, mixed> $line */
            $lines[] = new QuoteLine(
                type: PriceLineType::from($this->string($line, 'type')),
                code: $this->string($line, 'code'),
                description: $this->string($line, 'description'),
                amount: Money::ofMinor($this->integer($line, 'amountMinorUnits'), $currency),
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function coupon(array $body): ?CouponOutcome
    {
        $coupon = $body['coupon'] ?? null;

        if (! is_array($coupon)) {
            return null;
        }

        /** @var array<string, mixed> $coupon */
        $code = $this->string($coupon, 'code');

        if ($coupon['applied'] ?? false) {
            return CouponOutcome::applied($code);
        }

        return CouponOutcome::rejected(
            $code,
            CouponRejection::tryFrom($this->string($coupon, 'reason')) ?? CouponRejection::Unknown,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function money(array $body, string $key, Currency $currency): Money
    {
        return Money::ofMinor($this->integer($body, $key), $currency);
    }

    /**
     * A string field, or an empty string when it is absent or the wrong type.
     *
     * Empty rather than throwing because the callers all feed it into a
     * `from()`/`tryFrom()` that rejects it with a better message than a
     * generic type error would give.
     *
     * @param  array<string, mixed>  $source
     */
    private function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function integer(array $source, string $key): int
    {
        $value = $source[$key] ?? null;

        // Strict: a float here means the service lost a cent before we saw it.
        if (! is_int($value)) {
            throw new RuntimeException("Pricing response field [{$key}] is not an integer.");
        }

        return $value;
    }
}
