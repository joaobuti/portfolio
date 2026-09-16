<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a coupon the customer supplied was not applied.
 *
 * A rejected coupon is a normal outcome, not an error: the quote still stands
 * at full price. Modelling the reason means the UI can say "that code has
 * expired" instead of "something went wrong", and means a customer guessing
 * codes never looks like a failing dependency.
 */
enum CouponRejection: string
{
    /** No such code. */
    case Unknown = 'unknown';

    /** A fixed-amount coupon in a different currency than the plan. */
    case CurrencyMismatch = 'currency_mismatch';

    /** Nothing to discount — the amount is a credit or zero. */
    case NotApplicable = 'not_applicable';

    /** The pricing service was unreachable, so no coupon could be checked. */
    case Unavailable = 'unavailable';

    public function describe(): string
    {
        return match ($this) {
            self::Unknown => 'This coupon code was not recognised.',
            self::CurrencyMismatch => 'This coupon cannot be used with the plan currency.',
            self::NotApplicable => 'This coupon does not apply to a credit.',
            self::Unavailable => 'Coupons could not be checked. Please try again shortly.',
        };
    }
}
