<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CheckoutStatus;
use App\Enums\Currency;
use App\Exceptions\Billing\CheckoutException;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CheckoutService
{
    public function __construct(private Clock $clock) {}

    /** Caller holds the shared invoice mutation lock. */
    public function start(Invoice $invoice, CheckoutGateway $gateway): Invoice
    {
        $checkout = PaymentCheckout::query()->where('invoice_id', $invoice->id)->first();
        if ($checkout !== null) {
            if ($checkout->status === CheckoutStatus::Creating) {
                $checkout->update(['status' => CheckoutStatus::CreationUnknown]);
            } elseif ($checkout->status === CheckoutStatus::AwaitingPayment && $checkout->expires_at <= $this->clock->now()) {
                $checkout->update(['status' => CheckoutStatus::Expired]);
            }

            return $invoice->load('checkout');
        }
        if ($invoice->total->currency !== Currency::BRL || $invoice->total->minorUnits < 100) {
            throw new CheckoutException('InfinitePay checkout requires BRL and a minimum of 100 minor units.', 422);
        }
        $handle = config('infinitepay.handle');
        $webhook = config('infinitepay.webhook_url');
        $redirect = config('infinitepay.redirect_url');
        $key = config('infinitepay.api_key');
        if (! is_string($handle) || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $handle) !== 1
            || ! is_string($key) || trim($key) === '' || ! $this->httpsUrl($webhook)
            || ($redirect !== null && $redirect !== '' && ! $this->httpsUrl($redirect))) {
            throw new CheckoutException('InfinitePay merchant, API key and public HTTPS URLs must be configured.');
        }
        $ttl = config('infinitepay.checkout_ttl_seconds');
        $ttl = is_int($ttl) ? max(60, min(31536000, $ttl)) : 604800;
        $checkout = DB::transaction(function () use ($invoice, $handle, $webhook, $redirect, $ttl): PaymentCheckout {
            $id = (string) Str::uuid();
            $checkout = PaymentCheckout::query()->create([
                'uuid' => $id, 'invoice_id' => $invoice->id, 'status' => CheckoutStatus::Creating,
                'order_reference' => 'pb-'.$id, 'merchant_handle' => $handle,
                'amount_cents' => $invoice->total->minorUnits, 'currency' => $invoice->total->currency->value,
                'webhook_url' => $webhook, 'redirect_url' => $redirect === '' ? null : $redirect,
                'expires_at' => $invoice->payment_deadline_at === null ? $this->clock->now()->addSeconds($ttl)
                    : $this->clock->now()->addSeconds($ttl)->min($invoice->payment_deadline_at),
            ]);
            $invoice->update(['next_attempt_at' => null]);

            return $checkout;
        });
        // The intent is committed before HTTP. Uncertain creation is never
        // automatically repeated, including after a crash or an invalid reply.
        try {
            $url = $gateway->create($checkout->request());
            PaymentCheckout::query()->whereKey($checkout->id)
                ->whereIn('status', [CheckoutStatus::Creating, CheckoutStatus::CreationUnknown])
                ->update(['url' => $url, 'status' => CheckoutStatus::AwaitingPayment]);
        } catch (CheckoutException $exception) {
            PaymentCheckout::query()->whereKey($checkout->id)->where('status', CheckoutStatus::Creating)
                ->update(['status' => $exception->definitive ? CheckoutStatus::CreationFailed : CheckoutStatus::CreationUnknown]);
        }

        return $invoice->load('checkout');
    }

    private function httpsUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
