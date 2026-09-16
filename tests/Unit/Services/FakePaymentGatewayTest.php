<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\PaymentFailureReason;
use App\Services\Billing\FakePaymentGateway;
use App\Support\Money;

function charge(int $minorUnits, string $key = 'k'): App\DTOs\Billing\ChargeResult
{
    return (new FakePaymentGateway)->charge(
        Money::ofMinor($minorUnits, Currency::BRL),
        'customer-uuid',
        $key,
    );
}

describe('determinism', function (): void {
    it('succeeds for an ordinary amount', function (): void {
        $result = charge(10_000);

        expect($result->succeeded)->toBeTrue()
            ->and($result->gatewayReference)->toStartWith('fake_')
            ->and($result->failureReason)->toBeNull();
    });

    it('maps the trailing cents onto a specific failure', function (int $cents, PaymentFailureReason $reason): void {
        // The same convention real gateways use for test cards, for the same
        // reason: the unhappy paths have to be reachable on demand.
        $result = charge(10_000 + $cents);

        expect($result->succeeded)->toBeFalse()
            ->and($result->failureReason)->toBe($reason);
    })->with([
        [1, PaymentFailureReason::Declined],
        [2, PaymentFailureReason::InsufficientFunds],
        [3, PaymentFailureReason::ExpiredCard],
        [4, PaymentFailureReason::NetworkError],
    ]);

    it('gives the same answer for the same amount every time', function (): void {
        // A fake that fails randomly is a flaky suite and an undebuggable
        // local environment.
        expect(charge(10_001, 'a')->failureReason)
            ->toBe(charge(10_001, 'b')->failureReason);
    });

    it('treats a credit by its magnitude', function (): void {
        expect(charge(-10_001)->failureReason)->toBe(PaymentFailureReason::Declined);
    });
});

describe('idempotency', function (): void {
    it('returns the first result for a repeated key', function (): void {
        $gateway = new FakePaymentGateway;
        $amount = Money::ofMinor(10_000, Currency::BRL);

        $first = $gateway->charge($amount, 'customer', 'same-key');
        $second = $gateway->charge($amount, 'customer', 'same-key');

        expect($second->gatewayReference)->toBe($first->gatewayReference);
    });

    it('charges separately for a different key', function (): void {
        $gateway = new FakePaymentGateway;
        $amount = Money::ofMinor(10_000, Currency::BRL);

        expect($gateway->charge($amount, 'customer', 'key-1')->gatewayReference)
            ->not->toBe($gateway->charge($amount, 'customer', 'key-2')->gatewayReference);
    });
});

describe('the result object', function (): void {
    it('cannot describe a success that also failed', function (): void {
        $success = charge(10_000);

        expect($success->succeeded)->toBeTrue()
            ->and($success->failureReason)->toBeNull()
            ->and($success->isWorthRetrying())->toBeFalse();
    });

    it('delegates the retry policy to the reason', function (): void {
        expect(charge(10_001)->isWorthRetrying())->toBeTrue()
            ->and(charge(10_003)->isWorthRetrying())->toBeFalse();
    });
});
