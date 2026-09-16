<?php

declare(strict_types=1);

use App\DTOs\Pricing\PriceQuote;
use App\Enums\CouponRejection;
use App\Enums\Currency;
use App\Support\Money;

describe('the degraded fallback', function (): void {
    it('is the subtotal, untaxed and undiscounted', function (): void {
        $quote = PriceQuote::degraded(Money::ofMinor(4990, Currency::BRL));

        expect($quote->isDegraded())->toBeTrue()
            ->and($quote->total->minorUnits)->toBe(4990)
            ->and($quote->tax->isZero())->toBeTrue()
            ->and($quote->discount->isZero())->toBeTrue()
            ->and($quote->reconciles())->toBeTrue();
    });

    it('reports a coupon it could not check rather than dropping it', function (): void {
        $quote = PriceQuote::degraded(Money::ofMinor(4990, Currency::BRL), 'WELCOME10');

        expect($quote->coupon?->applied)->toBeFalse()
            ->and($quote->coupon?->reason)->toBe(CouponRejection::Unavailable);
    });

    it('has no coupon outcome when none was supplied', function (): void {
        expect(PriceQuote::degraded(Money::ofMinor(4990, Currency::BRL))->coupon)->toBeNull();
    });

    it('degrades a credit without changing its sign', function (): void {
        $quote = PriceQuote::degraded(Money::ofMinor(-4990, Currency::BRL));

        expect($quote->total->minorUnits)->toBe(-4990)
            ->and($quote->reconciles())->toBeTrue();
    });
});

describe('reconciliation', function (): void {
    it('holds for a normal quote', function (): void {
        $quote = new PriceQuote(
            subtotal: Money::ofMinor(10_000, Currency::BRL),
            discount: Money::ofMinor(1000, Currency::BRL),
            taxable: Money::ofMinor(9000, Currency::BRL),
            tax: Money::ofMinor(810, Currency::BRL),
            total: Money::ofMinor(9810, Currency::BRL),
        );

        expect($quote->reconciles())->toBeTrue()
            ->and($quote->hasTax())->toBeTrue()
            ->and($quote->hasDiscount())->toBeTrue();
    });

    it('fails loudly when the parts do not add up', function (): void {
        $quote = new PriceQuote(
            subtotal: Money::ofMinor(10_000, Currency::BRL),
            discount: Money::ofMinor(1000, Currency::BRL),
            taxable: Money::ofMinor(9000, Currency::BRL),
            tax: Money::ofMinor(810, Currency::BRL),
            total: Money::ofMinor(12_345, Currency::BRL),
        );

        expect($quote->reconciles())->toBeFalse();
    });
});
