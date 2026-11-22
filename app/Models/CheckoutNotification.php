<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $payment_checkout_id
 * @property string $transaction_nsu
 * @property string $slug
 * @property string $fingerprint
 * @property string $status
 * @property int $verification_count
 * @property CarbonImmutable|null $next_verification_at
 * @property string|null $reason
 * @property-read PaymentCheckout $checkout
 */
final class CheckoutNotification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return BelongsTo<PaymentCheckout, $this> */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckout::class, 'payment_checkout_id');
    }

    protected function casts(): array
    {
        return ['verification_count' => 'integer', 'next_verification_at' => 'immutable_datetime'];
    }
}
