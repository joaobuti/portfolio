<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Currency;
use App\Exceptions\CurrencyMismatchException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable monetary amount stored as an integer in the currency's minor unit.
 *
 * Two rules this class exists to enforce:
 *
 * 1. Money is never a float. `0.1 + 0.2 !== 0.3` is a rounding bug in a
 *    spreadsheet and a chargeback in a billing system.
 * 2. Money is never currency-agnostic. Adding BRL to USD is a programming
 *    error, so it throws instead of silently producing a wrong number.
 *
 * Deliberately framework-free: it depends on nothing but PHP itself, which is
 * what lets the whole pricing surface be unit tested in milliseconds.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    /**
     * Build from the minor unit (cents), which is how amounts are persisted.
     */
    public static function ofMinor(int $minorUnits, Currency|string $currency): self
    {
        return new self($minorUnits, self::resolveCurrency($currency));
    }

    /**
     * Build from the major unit (reais, dollars).
     *
     * Accepts a numeric string rather than a float on purpose — `Money::ofMajor(19.99)`
     * would already have lost precision before this method ever ran.
     */
    public static function ofMajor(string|int $majorUnits, Currency|string $currency): self
    {
        $resolved = self::resolveCurrency($currency);
        $value = (string) $majorUnits;

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a valid decimal amount.");
        }

        $scale = $resolved->minorUnitScale();
        $fraction = str_pad(substr($matches[3] ?? '', 0, $scale), $scale, '0');
        $minorUnits = (int) ($matches[2].$fraction);

        return new self($matches[1] === '-' ? -$minorUnits : $minorUnits, $resolved);
    }

    public static function zero(Currency|string $currency): self
    {
        return new self(0, self::resolveCurrency($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->minorUnits * $factor, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Take a fraction of this amount: `$monthly->prorate(12, 30)` is twelve
     * thirtieths of a month.
     *
     * Rounding policy is half-up on the exact rational, decided once here so it
     * is impossible for two call sites to round differently. Half-up (rather
     * than banker's rounding) is the convention every payment gateway and every
     * accountant reviewing an invoice will expect.
     */
    public function prorate(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Cannot prorate by a zero denominator.');
        }

        $scaled = $this->minorUnits * $numerator;
        $quotient = intdiv($scaled, $denominator);
        $remainder = $scaled % $denominator;

        if (abs($remainder) * 2 >= abs($denominator)) {
            $quotient += ($scaled < 0 ? -1 : 1);
        }

        return new self($quotient, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * Exact decimal representation, e.g. "19.90". Safe for APIs and ledgers.
     */
    public function toDecimal(): string
    {
        $scale = $this->currency->minorUnitScale();
        $sign = $this->minorUnits < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minorUnits), $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /**
     * Human-facing representation, e.g. "R$ 1.234,56".
     */
    public function format(): string
    {
        [$whole, $fraction] = explode('.', ltrim($this->toDecimal(), '-'));

        $grouped = number_format(
            (float) $whole,
            0,
            '.',
            $this->currency->thousandsSeparator(),
        );

        $sign = $this->minorUnits < 0 ? '-' : '';

        return $sign.$this->currency->symbol().' '.$grouped.$this->currency->decimalSeparator().$fraction;
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency);
        }
    }

    private static function resolveCurrency(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::from($currency);
    }
}
