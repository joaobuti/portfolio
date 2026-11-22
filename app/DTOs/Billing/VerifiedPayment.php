<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class VerifiedPayment
{
    public function __construct(
        public PaymentVerification $reference,
        public int $amount,
        public int $paidAmount,
        public int $installments,
        public string $captureMethod,
    ) {}
}
