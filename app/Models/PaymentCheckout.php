<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Billing\CheckoutRequest;
use App\Enums\CheckoutStatus;
use App\Enums\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property CheckoutStatus $status
 * @property string $order_reference
 * @property string $merchant_handle
 * @property int $amount_cents
 * @property string $currency
 * @property string $webhook_url
 * @property string|null $redirect_url
 * @property string|null $url
 * @property CarbonImmutable $expires_at
 * @property string|null $transaction_nsu
 * @property int|null $paid_amount_cents
 * @property int|null $installments
 * @property string|null $capture_method
 * @property CarbonImmutable|null $verified_at
 * @property-read Invoice $invoice
 */
final class PaymentCheckout extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function request(): CheckoutRequest
    {
        return new CheckoutRequest($this->order_reference, $this->merchant_handle,
            Money::ofMinor($this->amount_cents, Currency::from($this->currency)),
            $this->expires_at->toDateTimeImmutable(), $this->webhook_url, $this->redirect_url);
    }

    protected function casts(): array
    {
        return ['status' => CheckoutStatus::class, 'amount_cents' => 'integer', 'paid_amount_cents' => 'integer', 'installments' => 'integer', 'expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }
}
