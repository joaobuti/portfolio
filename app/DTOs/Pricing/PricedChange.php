<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\DTOs\Subscriptions\ProrationResult;

/**
 * A plan change with its tax and coupons worked out.
 *
 * Two objects rather than one flattened total, because they come from two
 * places with different reliability: the proration is computed locally and is
 * always correct, while the quote comes from another service over a network
 * and may be degraded. Merging them would lose exactly the distinction a
 * caller needs to decide whether it can charge this.
 */
final readonly class PricedChange
{
    public function __construct(
        public ProrationResult $proration,
        public PriceQuote $quote,
        public ?string $quoteId = null,
    ) {}

    /**
     * Whether this is safe to turn into a charge.
     *
     * False when the pricing service could not be reached: the amount would be
     * missing its tax, and billing an untaxed amount is a compliance problem
     * that surfaces months later in a reconciliation nobody enjoys.
     */
    public function isChargeable(): bool
    {
        return ! $this->quote->isDegraded();
    }
}
