<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CheckoutRequest;
use App\DTOs\Billing\PaymentVerification;
use App\DTOs\Billing\VerifiedPayment;

interface CheckoutGateway
{
    public function create(CheckoutRequest $request): string;

    public function check(PaymentVerification $reference): ?VerifiedPayment;
}
