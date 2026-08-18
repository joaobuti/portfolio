<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use App\Exceptions\DomainException;

final class DuplicateActiveSubscriptionException extends DomainException
{
    private function __construct(
        private readonly string $customerUuid,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forCustomer(string $customerUuid): self
    {
        return new self(
            $customerUuid,
            'This customer already has a subscription that grants access.',
        );
    }

    public function errorCode(): string
    {
        return 'subscription.duplicate_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array{customer: string}
     */
    public function context(): array
    {
        return ['customer' => $this->customerUuid];
    }
}
