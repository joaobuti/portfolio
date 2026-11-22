<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use App\Exceptions\DomainException;

final class PlanChangeNotAllowedException extends DomainException
{
    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function samePlan(): self
    {
        return new self('same_plan', 'The subscription is already on this plan.');
    }

    public static function inactivePlan(string $planCode): self
    {
        return new self('inactive_plan', "Plan [{$planCode}] is not available for new subscriptions.");
    }

    public static function currencyChange(): self
    {
        return new self(
            'currency_change',
            'Changing the billing currency requires a new subscription.',
        );
    }

    public static function statusDoesNotAllow(string $status): self
    {
        return new self('status', "A subscription in \"{$status}\" cannot change plans.");
    }

    public static function invalidQuote(): self
    {
        return new self('invalid_quote', 'The quote does not match this subscription, plan or billing period.');
    }

    public static function pendingPayment(): self
    {
        return new self('pending_adjustment', 'Resolve the pending plan adjustment before changing plans again.');
    }

    public function errorCode(): string
    {
        return 'subscription.plan_change_not_allowed';
    }

    /**
     * @return array{reason: string}
     */
    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
