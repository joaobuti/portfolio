<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class PaymentVerification
{
    public function __construct(
        public string $orderReference,
        public string $merchantHandle,
        public string $transactionNsu,
        public string $slug,
    ) {}
}
