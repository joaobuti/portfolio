<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\DTOs\Billing\ChargeResult;
use App\Enums\PaymentFailureReason;
use App\Services\Billing\PaymentGateway;
use App\Support\Money;

final readonly class SimulationGateway implements PaymentGateway
{
    public function __construct(private bool $approved) {}

    public function charge(Money $amount, string $customerReference, string $idempotencyKey): ChargeResult
    {
        return $this->approved ? ChargeResult::succeeded('demo_'.hash('sha256', $idempotencyKey))
            : ChargeResult::failed(PaymentFailureReason::Declined, 'demo_declined', 'Simulated decline');
    }

    public function name(): string
    {
        return 'demo';
    }
}
