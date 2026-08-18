<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Currency;

/**
 * Raised when two amounts in different currencies are combined.
 *
 * This is a programming error rather than a user-facing business rule, so it
 * maps to 500 — if it ever reaches a customer, we shipped a bug.
 */
final class CurrencyMismatchException extends DomainException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self("Cannot operate on {$left->value} and {$right->value} amounts.");
    }

    public function errorCode(): string
    {
        return 'money.currency_mismatch';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
