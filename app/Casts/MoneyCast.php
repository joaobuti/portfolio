<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps an `(amount_cents, currency)` column pair onto a single {@see Money}
 * attribute.
 *
 * The pair matters. Storing a bare integer would let a BRL row and a USD row
 * be summed by an innocent `SUM(amount_cents)`; keeping the currency beside
 * the amount and rebuilding a Money on read means the invalid operation fails
 * loudly in PHP instead of quietly in a report.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final readonly class MoneyCast implements CastsAttributes
{
    public function __construct(
        private string $amountColumn = 'amount_cents',
        private string $currencyColumn = 'currency',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amount = $attributes[$this->amountColumn] ?? null;
        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($amount === null || $currency === null) {
            return null;
        }

        if ((! is_int($amount) && ! is_string($amount)) || ! is_string($currency)) {
            throw new InvalidArgumentException('Stored money requires an integer or string amount and a string currency.');
        }

        return Money::ofMinor((int) $amount, Currency::from($currency));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [
                $this->amountColumn => null,
                $this->currencyColumn => null,
            ];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(sprintf(
                'Attribute [%s] on [%s] must be set to a %s instance, %s given.',
                $key,
                $model::class,
                Money::class,
                get_debug_type($value),
            ));
        }

        return [
            $this->amountColumn => $value->minorUnits,
            $this->currencyColumn => $value->currency->value,
        ];
    }
}
