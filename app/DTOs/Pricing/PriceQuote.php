<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\Enums\CouponRejection;
use App\Support\Money;

/**
 * What a customer actually owes once tax and coupons are applied.
 *
 * ## The invariant
 *
 * `subtotal - discount + tax === total`, always. It is asserted in the tests
 * rather than merely documented, because a quote that does not reconcile is a
 * quote that will eventually be argued about with a customer.
 *
 * ## Degraded quotes
 *
 * `$degraded` is true when the pricing service could not be reached and this
 * quote is the fallback: the subtotal, untaxed and undiscounted. It is a flag
 * rather than an exception because a plan-change **preview** should still show
 * the customer a number, while an actual **charge** must not silently skip tax
 * — and only the caller knows which of the two it is doing.
 *
 * Callers that move money are expected to check {@see isDegraded()} and refuse.
 * See ADR-0005 for why this is not decided here.
 */
final readonly class PriceQuote
{
    /**
     * @param  list<QuoteLine>  $lines
     */
    public function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $taxable,
        public Money $tax,
        public Money $total,
        public array $lines = [],
        public ?CouponOutcome $coupon = null,
        public ?string $taxRegion = null,
        public bool $degraded = false,
    ) {}

    /**
     * The quote we return when the pricing service is unreachable.
     *
     * No tax and no discount — never an estimate. Guessing a tax rate from a
     * stale cache is how a customer is charged an amount no rule in the system
     * can explain six months later.
     */
    public static function degraded(Money $subtotal, ?string $couponCode = null): self
    {
        $zero = Money::zero($subtotal->currency);

        return new self(
            subtotal: $subtotal,
            discount: $zero,
            taxable: $subtotal,
            tax: $zero,
            total: $subtotal,
            lines: [],
            coupon: $couponCode === null
                ? null
                : CouponOutcome::rejected($couponCode, CouponRejection::Unavailable),
            degraded: true,
        );
    }

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    public function hasDiscount(): bool
    {
        return ! $this->discount->isZero();
    }

    public function hasTax(): bool
    {
        return ! $this->tax->isZero();
    }

    /**
     * Proof that the parts add up. Cheap enough to assert wherever a quote
     * crosses a boundary, which is the point of having it.
     */
    public function reconciles(): bool
    {
        return $this->subtotal
            ->subtract($this->discount)
            ->add($this->tax)
            ->equals($this->total);
    }
}
