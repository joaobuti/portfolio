<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

final readonly class ChangePlanData
{
    public function __construct(
        public string $planCode,
        /**
         * Force a downgrade to apply now instead of at the end of the period.
         *
         * Off by default: a customer who downgrades has already paid for the
         * more expensive plan, and taking it away immediately is both hostile
         * and a refund we would rather not process. Support staff can override
         * it when a customer genuinely asks.
         */
        public bool $applyDowngradeImmediately = false,
        public ?string $quoteId = null,
        public ?string $couponCode = null,
    ) {}
}
