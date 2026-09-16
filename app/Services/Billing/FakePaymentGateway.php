<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\ChargeResult;
use App\Enums\PaymentFailureReason;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * A gateway that takes no money and needs no account.
 *
 * ## Why this ships in the application and not only in the tests
 *
 * The whole stack has to run for someone who has just cloned the repository.
 * A billing module whose happy path requires a third-party account is a
 * billing module nobody can try, and a `docker compose up` that half-works
 * teaches the wrong thing about the system.
 *
 * ## Determinism, and why the amount decides the outcome
 *
 * The result is derived from the amount rather than from a random number, so
 * the same input always produces the same outcome. A fake that fails randomly
 * makes a flaky test suite and an undebuggable local environment.
 *
 * The rule: amounts ending in specific cents trigger specific failures. It is
 * the convention the real gateways use for their test cards, for the same
 * reason — you need a way to reach the unhappy paths on demand.
 *
 *   ...01  declined
 *   ...02  insufficient funds
 *   ...03  expired card
 *   ...04  network error
 *   anything else  succeeds
 *
 * Idempotency is honoured: charging twice with the same key returns the first
 * result rather than a second charge. In-memory, because this adapter lives
 * for one request — the durable guard is the invoice status, checked by
 * {@see BillingService} before the gateway is ever reached.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, ChargeResult> */
    private array $seen = [];

    public function charge(
        Money $amount,
        string $customerReference,
        string $idempotencyKey,
    ): ChargeResult {
        if (isset($this->seen[$idempotencyKey])) {
            return $this->seen[$idempotencyKey];
        }

        return $this->seen[$idempotencyKey] = $this->outcomeFor($amount);
    }

    public function name(): string
    {
        return 'fake';
    }

    private function outcomeFor(Money $amount): ChargeResult
    {
        $reason = match (abs($amount->minorUnits) % 100) {
            1 => PaymentFailureReason::Declined,
            2 => PaymentFailureReason::InsufficientFunds,
            3 => PaymentFailureReason::ExpiredCard,
            4 => PaymentFailureReason::NetworkError,
            default => null,
        };

        if ($reason === null) {
            return ChargeResult::succeeded('fake_'.Str::lower(Str::ulid()->toString()));
        }

        return ChargeResult::failed(
            reason: $reason,
            code: 'fake_'.$reason->value,
            message: $reason->label(),
        );
    }
}
