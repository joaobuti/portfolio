<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Exceptions\DomainException;

final class CheckoutException extends DomainException
{
    public function __construct(string $message = 'Checkout is temporarily unavailable.', private readonly int $status = 503, public readonly bool $definitive = false)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'checkout.unavailable';
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
