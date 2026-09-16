<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\ChargeResult;
use App\Support\Money;

/**
 * Takes money.
 *
 * ## Why the interface earns its place
 *
 * Unlike a repository over Eloquent, this one has two real implementations
 * from day one — {@see FakePaymentGateway} and whatever Stripe or Pagar.me
 * adapter ships later — and the second is guaranteed to arrive. That is the
 * test ADR-0001 sets for an abstraction, and this passes it.
 *
 * ## The contract
 *
 * `charge()` **never throws for a payment failure.** A declined card is a
 * normal outcome, not an exception: it is the single most common thing that
 * happens to this method, and modelling it as a throw would put the routine
 * path in a catch block. It returns a {@see ChargeResult}.
 *
 * It *may* throw for a transport failure it cannot classify. Implementations
 * should prefer mapping those to `PaymentFailureReason::NetworkError` so the
 * caller has one shape to reason about.
 *
 * ## Idempotency is the caller's problem, deliberately
 *
 * `$idempotencyKey` is passed to the gateway so a retried job cannot charge
 * twice at the vendor. The key is derived from the invoice, not generated
 * here, because only the caller knows whether this is a retry of the same
 * logical charge or a genuinely new one.
 */
interface PaymentGateway
{
    /**
     * @param  string  $idempotencyKey  Stable across retries of the same logical charge.
     */
    public function charge(
        Money $amount,
        string $customerReference,
        string $idempotencyKey,
    ): ChargeResult;

    /**
     * A short name for logs and the charge_attempts audit trail — `fake`,
     * `stripe`. Not a display name; it is written to rows that outlive the
     * adapter.
     */
    public function name(): string;
}
