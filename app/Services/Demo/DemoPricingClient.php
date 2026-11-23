<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\DTOs\Pricing\PriceQuote;
use App\Services\Pricing\PricingClient;
use App\Support\Money;

/** Deliberately zero-tax sample pricing, only in the isolated simulation. */
final readonly class DemoPricingClient implements PricingClient
{
    public function quote(Money $amount, string $region, ?string $couponCode = null): PriceQuote
    {
        $zero = Money::zero($amount->currency);

        return new PriceQuote($amount, $zero, $amount, $zero, $amount, taxRegion: 'DEMO');
    }
}
