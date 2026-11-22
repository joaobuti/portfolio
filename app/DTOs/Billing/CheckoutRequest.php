<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Support\Money;
use DateTimeImmutable;

final readonly class CheckoutRequest
{
    public function __construct(
        public string $orderReference,
        public string $merchantHandle,
        public Money $amount,
        public DateTimeImmutable $expiresAt,
        public string $webhookUrl,
        public ?string $redirectUrl,
    ) {}
}
