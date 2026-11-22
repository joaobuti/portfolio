<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Enums\Currency;
use App\Models\Plan;
use App\Support\Money;

it('reads database integer amounts and integer strings', function (int|string $amount): void {
    $money = (new MoneyCast)->get(new Plan, 'amount', null, [
        'amount_cents' => $amount,
        'currency' => 'BRL',
    ]);

    expect($money?->minorUnits)->toBe((int) $amount)
        ->and($money?->currency)->toBe(Currency::BRL);
})->with([1000, '1000', -1000, '-1000', 0, '0']);

it('preserves null money columns', function (): void {
    $cast = new MoneyCast;

    expect($cast->get(new Plan, 'amount', null, []))->toBeNull()
        ->and($cast->set(new Plan, 'amount', null, []))->toBe([
            'amount_cents' => null,
            'currency' => null,
        ]);
});

it('only accepts Money instances when writing', function (): void {
    (new MoneyCast)->set(new Plan, 'amount', 1000, []);
})->throws(InvalidArgumentException::class);

it('writes a Money value as integer amount and currency columns', function (): void {
    expect((new MoneyCast)->set(new Plan, 'amount', Money::ofMinor(1000, Currency::BRL), []))
        ->toBe(['amount_cents' => 1000, 'currency' => 'BRL']);
});

it('rejects invalid stored column types', function (mixed $amount, mixed $currency): void {
    (new MoneyCast)->get(new Plan, 'amount', null, [
        'amount_cents' => $amount,
        'currency' => $currency,
    ]);
})->with([
    [10.5, 'BRL'],
    [true, 'BRL'],
    [[], 'BRL'],
    [1000, true],
    [1000, []],
])->throws(InvalidArgumentException::class);
