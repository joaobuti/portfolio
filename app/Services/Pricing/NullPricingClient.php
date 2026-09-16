<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTOs\Pricing\PriceQuote;
use App\Support\Money;

/**
 * The client used when pricing is switched off.
 *
 * Every quote comes back degraded, which is exactly what an unreachable
 * service produces — so the degraded path is exercised by default in the test
 * suite instead of being the branch nobody runs until production. Turning
 * `pricing.enabled` off locally gives a working stack with no Node container.
 */
final readonly class NullPricingClient implements PricingClient
{
    public function quote(Money $amount, string $region, ?string $couponCode = null): PriceQuote
    {
        return PriceQuote::degraded($amount, $couponCode);
    }
}
