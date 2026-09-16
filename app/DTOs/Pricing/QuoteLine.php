<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\Enums\PriceLineType;
use App\Support\Money;

/**
 * One named adjustment in a quote — a discount or a tax.
 *
 * The breakdown exists for the same reason {@see \App\DTOs\Subscriptions\ProrationResult}
 * returns its workings: "why was I charged this?" is the most common billing
 * support ticket, and a total with no line items cannot answer it.
 *
 * The amount is signed against the subtotal: negative for a discount, positive
 * for a tax. One signed number rather than a magnitude plus a direction flag,
 * because the sum of the lines must equal the difference between subtotal and
 * total, and two representations of sign is how that stops being true.
 */
final readonly class QuoteLine
{
    public function __construct(
        public PriceLineType $type,
        public string $code,
        public string $description,
        public Money $amount,
    ) {}
}
