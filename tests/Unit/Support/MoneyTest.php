<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Exceptions\CurrencyMismatchException;
use App\Support\Money;

describe('construction', function (): void {
    it('parses a decimal string exactly', function (): void {
        expect(Money::ofMajor('19.90', Currency::BRL)->minorUnits)->toBe(1990);
    });

    it('parses whole numbers and negatives', function (string $input, int $expected): void {
        expect(Money::ofMajor($input, Currency::BRL)->minorUnits)->toBe($expected);
    })->with([
        ['20', 2000],
        ['0', 0],
        ['-19.90', -1990],
        ['0.05', 5],
        // More precision than the currency has is truncated, not rounded up
        // into a cent the customer never agreed to.
        ['1.999', 199],
    ]);

    it('rejects anything that is not a decimal', function (string $input): void {
        Money::ofMajor($input, Currency::BRL);
    })->with(['abc', '1,90', '1.2.3', ''])->throws(InvalidArgumentException::class);
});

describe('arithmetic', function (): void {
    it('does not suffer from float error', function (): void {
        $sum = Money::ofMajor('0.1', Currency::BRL)->add(Money::ofMajor('0.2', Currency::BRL));

        expect($sum->equals(Money::ofMajor('0.3', Currency::BRL)))->toBeTrue();
    });

    it('refuses to mix currencies', function (): void {
        Money::ofMinor(100, Currency::BRL)->add(Money::ofMinor(100, Currency::USD));
    })->throws(CurrencyMismatchException::class);

    it('subtracts into negative territory', function (): void {
        expect(
            Money::ofMajor('10.00', Currency::BRL)
                ->subtract(Money::ofMajor('30.00', Currency::BRL))
                ->toDecimal(),
        )->toBe('-20.00');
    });
});

describe('proration rounding', function (): void {
    it('rounds half up', function (): void {
        // 100 * 1 / 8 = 12.5
        expect(Money::ofMinor(100, Currency::BRL)->prorate(1, 8)->minorUnits)->toBe(13);
    });

    it('rounds half away from zero for negative amounts', function (): void {
        expect(Money::ofMinor(-100, Currency::BRL)->prorate(1, 8)->minorUnits)->toBe(-13);
    });

    it('is the identity for a full period', function (): void {
        expect(Money::ofMinor(9990, Currency::BRL)->prorate(30, 30)->minorUnits)->toBe(9990);
    });

    it('is zero for no remaining days', function (): void {
        expect(Money::ofMinor(9990, Currency::BRL)->prorate(0, 30)->isZero())->toBeTrue();
    });

    it('refuses a zero denominator instead of dividing by it', function (): void {
        Money::ofMinor(100, Currency::BRL)->prorate(1, 0);
    })->throws(InvalidArgumentException::class);
});

describe('presentation', function (): void {
    it('formats per currency convention', function (Currency $currency, string $expected): void {
        expect(Money::ofMinor(123456, $currency)->format())->toBe($expected);
    })->with([
        [Currency::BRL, 'R$ 1.234,56'],
        [Currency::USD, '$ 1,234.56'],
    ]);

    it('renders an exact decimal for machines', function (int $minor, string $expected): void {
        expect(Money::ofMinor($minor, Currency::BRL)->toDecimal())->toBe($expected);
    })->with([
        [5, '0.05'],
        [-5, '-0.05'],
        [0, '0.00'],
        [100000, '1000.00'],
    ]);

    it('serialises with both the machine value and the human one', function (): void {
        expect(Money::ofMinor(1990, Currency::BRL)->jsonSerialize())->toBe([
            'amount' => 1990,
            'currency' => 'BRL',
            'formatted' => 'R$ 19,90',
        ]);
    });
});
