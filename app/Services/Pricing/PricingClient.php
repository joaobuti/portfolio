<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTOs\Pricing\PriceQuote;
use App\Support\Money;

/**
 * Prices an amount against the regional tax and coupon rules.
 *
 * An interface with two implementations that both really exist — the HTTP one
 * and the null one used in tests and offline development — rather than the
 * speculative kind this codebase avoids elsewhere (see ADR-0001 on why there
 * is no repository interface).
 *
 * ## The contract implementations must honour
 *
 * `quote()` **never throws for a dependency failure.** Every transport error,
 * timeout, malformed response and open circuit comes back as a degraded quote
 * with {@see PriceQuote::isDegraded()} true. A caller that moves money is
 * expected to check that flag and refuse; a caller showing a preview is not.
 *
 * This is the opposite of the usual advice, and it is deliberate: an exception
 * here would put a third-party outage in the path of a plan change, and the
 * one thing worse than an untaxed preview is a customer who cannot change
 * their plan because a tax service is down.
 */
interface PricingClient
{
    /**
     * @param  Money  $amount  Signed. A negative amount is a credit, taxed symmetrically.
     * @param  string  $region  `COUNTRY` or `COUNTRY-SUBDIVISION`, e.g. `BR-SP`.
     */
    public function quote(Money $amount, string $region, ?string $couponCode = null): PriceQuote;
}
