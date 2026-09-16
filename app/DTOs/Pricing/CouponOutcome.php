<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\Enums\CouponRejection;

/**
 * What happened to the coupon the customer supplied.
 *
 * Distinct from "no coupon at all", which is represented by a null outcome on
 * {@see PriceQuote}. The difference matters to the UI: nothing to show versus
 * a message explaining why the code did not work.
 */
final readonly class CouponOutcome
{
    public function __construct(
        public string $code,
        public bool $applied,
        public ?CouponRejection $reason = null,
    ) {}

    public static function applied(string $code): self
    {
        return new self($code, true);
    }

    public static function rejected(string $code, CouponRejection $reason): self
    {
        return new self($code, false, $reason);
    }
}
