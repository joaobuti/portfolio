<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\PaymentFailureReason;

/**
 * What the gateway said.
 *
 * Two named constructors and no public one, so an impossible result — a
 * success carrying a failure reason, a failure with no reason — cannot be
 * built. That is cheaper than validating it afterwards and impossible to
 * forget.
 *
 * The gateway's own code and message ride along untouched. Support eventually
 * needs the vendor's words, and a mapping bug is only findable if the input
 * survived the mapping.
 */
final readonly class ChargeResult
{
    private function __construct(
        public bool $succeeded,
        public ?PaymentFailureReason $failureReason = null,
        public ?string $gatewayReference = null,
        public ?string $gatewayCode = null,
        public ?string $gatewayMessage = null,
    ) {}

    public static function succeeded(string $gatewayReference, ?string $code = null): self
    {
        return new self(
            succeeded: true,
            gatewayReference: $gatewayReference,
            gatewayCode: $code,
        );
    }

    public static function failed(
        PaymentFailureReason $reason,
        ?string $code = null,
        ?string $message = null,
        ?string $gatewayReference = null,
    ): self {
        return new self(
            succeeded: false,
            failureReason: $reason,
            gatewayReference: $gatewayReference,
            gatewayCode: $code,
            gatewayMessage: $message,
        );
    }

    /**
     * Whether another attempt could plausibly work.
     *
     * Delegates to the reason rather than deciding here: the policy belongs to
     * the enum, and this object is a record of what happened.
     */
    public function isWorthRetrying(): bool
    {
        return ! $this->succeeded
            && $this->failureReason?->isWorthRetrying() === true;
    }
}
