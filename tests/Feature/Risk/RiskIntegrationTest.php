<?php

declare(strict_types=1);

use App\Enums\PaymentFailureReason;
use App\Enums\RiskBand;
use App\Events\Billing\PaymentFailed;
use App\Listeners\Billing\PublishPaymentFailedForRisk;
use App\Models\ChargeAttempt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Redis;

/**
 * Both ends of the Laravel side of the round trip: what we publish for the
 * Python worker, and what we do with what it publishes back.
 *
 * The worker itself is tested in services/risk-worker against a real Redis.
 * What is under test here is our half of the contract — and the envelope
 * assertions below are deliberately literal, because they are the shape another
 * repository parses.
 */
beforeEach(function (): void {
    freezeClockAt('2026-09-03 10:00:00');

    config()->set('risk.enabled', true);
    config()->set('risk.streams.events', 'test:risk:events');
    config()->set('risk.streams.scores', 'test:risk:scores');

    Redis::connection('messaging')->del('test:risk:events');
    Redis::connection('messaging')->del('test:risk:scores');

    $this->customer = Customer::factory()->create();
});

afterEach(function (): void {
    Redis::connection('messaging')->del('test:risk:events');
    Redis::connection('messaging')->del('test:risk:scores');
});

function publishFailure(Customer $customer, ?Subscription $subscription = null): void
{
    $invoice = Invoice::factory()
        ->for($customer)
        ->when($subscription !== null, fn ($factory) => $factory->for($subscription))
        ->decliningAmount()
        ->create(['attempt_count' => 2]);

    $attempt = ChargeAttempt::factory()
        ->for($invoice)
        ->failed(PaymentFailureReason::ExpiredCard)
        ->create();

    app(PublishPaymentFailedForRisk::class)(new PaymentFailed(
        invoice: $invoice->fresh(),
        attempt: $attempt,
        reason: PaymentFailureReason::ExpiredCard,
        attemptNumber: 2,
        willRetry: false,
    ));
}

describe('publishing for the worker', function (): void {
    it('puts a well-formed envelope on the list', function (): void {
        publishFailure($this->customer);

        $raw = Redis::connection('messaging')->lpop('test:risk:events');
        expect($raw)->toBeString();

        $message = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        expect($message['event'])->toBe('payment.failed')
            ->and($message['message_id'])->toBeString()->not->toBeEmpty()
            ->and($message['data']['customer_uuid'])->toBe($this->customer->uuid)
            ->and($message['data']['reason'])->toBe('expired_card')
            ->and($message['data']['attempt_number'])->toBe(2)
            ->and($message['data']['will_retry'])->toBeFalse();
    });

    it('sends the amount as an integer in minor units', function (): void {
        // The rule most likely to be broken by a JSON boundary. A float here
        // would be rejected by the worker's envelope parser, by design.
        publishFailure($this->customer);

        $message = json_decode((string) Redis::connection('messaging')->lpop('test:risk:events'), true, 512, JSON_THROW_ON_ERROR);

        expect($message['data']['amount_minor'])->toBeInt()->toBe(10_001)
            ->and($message['data']['currency'])->toBe('BRL');
    });

    it('carries the correlation id across the boundary', function (): void {
        // The reason the correlation middleware was built before this listener
        // existed: one customer action, traceable from HTTP through Redis into
        // a Python process.
        Context::add('correlation_id', 'trace-abc-123');

        publishFailure($this->customer);

        $message = json_decode((string) Redis::connection('messaging')->lpop('test:risk:events'), true, 512, JSON_THROW_ON_ERROR);

        expect($message['correlation_id'])->toBe('trace-abc-123');
    });

    it('gives each publish its own message id', function (): void {
        // The worker deduplicates on it, so two distinct failures must never
        // collide.
        publishFailure($this->customer);
        publishFailure($this->customer);

        $first = json_decode((string) Redis::connection('messaging')->lpop('test:risk:events'), true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode((string) Redis::connection('messaging')->lpop('test:risk:events'), true, 512, JSON_THROW_ON_ERROR);

        expect($first['message_id'])->not->toBe($second['message_id']);
    });

    it('publishes nothing when the integration is switched off', function (): void {
        config()->set('risk.enabled', false);

        publishFailure($this->customer);

        expect(Redis::connection('messaging')->llen('test:risk:events'))->toBe(0);
    });
});

describe('consuming the worker answers', function (): void {
    function pushScore(string $customerUuid, int $score, string $band): void
    {
        Redis::connection('messaging')->rpush('test:risk:scores', json_encode([
            'event' => 'risk.scored',
            'in_reply_to' => 'msg-1',
            'correlation_id' => 'corr-1',
            'data' => [
                'customer_uuid' => $customerUuid,
                'invoice_uuid' => 'inv-1',
                'score' => $score,
                'band' => $band,
                'factors' => [['code' => 'reason:expired_card', 'points' => 60]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    it('applies a score to the customer', function (): void {
        pushScore($this->customer->uuid, 79, 'high');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        $customer = $this->customer->fresh();

        expect($customer?->risk_score)->toBe(79)
            ->and($customer?->risk_band)->toBe(RiskBand::High)
            ->and($customer?->risk_scored_at?->toDateTimeString())->toBe('2026-09-03 10:00:00');
    });

    it('drains the whole list', function (): void {
        $other = Customer::factory()->create();
        pushScore($this->customer->uuid, 20, 'low');
        pushScore($other->uuid, 90, 'high');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect(Redis::connection('messaging')->llen('test:risk:scores'))->toBe(0)
            ->and($this->customer->fresh()?->risk_band)->toBe(RiskBand::Low)
            ->and($other->fresh()?->risk_band)->toBe(RiskBand::High);
    });

    it('discards an unparseable message without stranding the rest', function (): void {
        // The producer is another service on its own deploy cycle. One bad
        // message must not abort the drain.
        Redis::connection('messaging')->rpush('test:risk:scores', 'not json');
        pushScore($this->customer->uuid, 55, 'medium');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect($this->customer->fresh()?->risk_band)->toBe(RiskBand::Medium);
    });

    it('discards a band this version does not know', function (): void {
        // A producer running ahead of us. Logged, not applied.
        pushScore($this->customer->uuid, 55, 'catastrophic');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect($this->customer->fresh()?->risk_score)->toBeNull();
    });

    it('discards a score for a customer that no longer exists', function (): void {
        pushScore('00000000-0000-4000-8000-000000000000', 55, 'medium');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect(Redis::connection('messaging')->llen('test:risk:scores'))->toBe(0);
    });

    it('never records a score outside 0-100', function (): void {
        pushScore($this->customer->uuid, 5000, 'high');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect($this->customer->fresh()?->risk_score)->toBe(100);
    });

    it('stops at the configured limit', function (): void {
        pushScore($this->customer->uuid, 10, 'low');
        pushScore($this->customer->uuid, 20, 'low');

        $this->artisan('risk:consume-scores', ['--limit' => 1])->assertSuccessful();

        expect(Redis::connection('messaging')->llen('test:risk:scores'))->toBe(1);
    });

    it('does nothing when the integration is switched off', function (): void {
        config()->set('risk.enabled', false);
        pushScore($this->customer->uuid, 79, 'high');

        $this->artisan('risk:consume-scores')->assertSuccessful();

        expect(Redis::connection('messaging')->llen('test:risk:scores'))->toBe(1)
            ->and($this->customer->fresh()?->risk_score)->toBeNull();
    });
});
