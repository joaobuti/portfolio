<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentFailureReason;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ChargeAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to the payment gateway, recorded whether it worked or not.
 *
 * Append-only by convention — nothing in the application updates or deletes a
 * row. A second attempt is a second row, which is what makes "how many times
 * did you charge me?" answerable at all.
 *
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property bool $succeeded
 * @property PaymentFailureReason|null $failure_reason
 * @property string|null $gateway_code
 * @property string|null $gateway_message
 * @property string|null $gateway_reference
 * @property Money $amount
 * @property string $currency
 * @property CarbonImmutable $attempted_at
 * @property-read Invoice $invoice
 */
#[Fillable([
    'invoice_id',
    'succeeded',
    'failure_reason',
    'gateway_code',
    'gateway_message',
    'gateway_reference',
    'amount',
    'attempted_at',
])]
final class ChargeAttempt extends Model
{
    /** @use HasFactory<ChargeAttemptFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'failure_reason' => PaymentFailureReason::class,
            'amount' => MoneyCast::class.':amount_cents,currency',
            'attempted_at' => 'immutable_datetime',
        ];
    }
}
