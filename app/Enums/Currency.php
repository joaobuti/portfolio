<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Currencies supported by the billing engine.
 *
 * The minor unit is what makes {@see \App\Support\Money} safe: every amount is
 * stored as an integer in the smallest unit of its currency, never as a float.
 */
enum Currency: string
{
    case BRL = 'BRL';
    case USD = 'USD';
    case EUR = 'EUR';

    /**
     * Number of decimal places the currency subdivides into.
     */
    public function minorUnitScale(): int
    {
        return match ($this) {
            self::BRL, self::USD, self::EUR => 2,
        };
    }

    /**
     * How many minor units make up one major unit (100 cents = 1 real).
     */
    public function minorUnitFactor(): int
    {
        return 10 ** $this->minorUnitScale();
    }

    public function symbol(): string
    {
        return match ($this) {
            self::BRL => 'R$',
            self::USD => '$',
            self::EUR => '€',
        };
    }

    public function decimalSeparator(): string
    {
        return $this === self::BRL ? ',' : '.';
    }

    public function thousandsSeparator(): string
    {
        return $this === self::BRL ? '.' : ',';
    }
}
