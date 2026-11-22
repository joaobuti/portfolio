<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\PaymentVerification;
use App\Exceptions\Billing\CheckoutException;
use App\Jobs\Billing\VerifyCheckout;
use App\Models\CheckoutNotification;
use App\Models\PaymentCheckout;
use App\Support\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class CheckoutNotificationService
{
    public function __construct(private CheckoutGateway $gateway, private BillingService $billing, private Clock $clock) {}

    public function accept(string $order, string $transaction, string $slug, bool $retry = false): void
    {
        $checkout = PaymentCheckout::query()->where('order_reference', $order)->first();
        if ($checkout === null) {
            return;
        }
        $notification = CheckoutNotification::query()->firstOrCreate(
            ['fingerprint' => hash('sha256', $checkout->uuid."\0".$transaction."\0".$slug)],
            ['payment_checkout_id' => $checkout->id, 'transaction_nsu' => $transaction, 'slug' => $slug,
                'status' => 'pending', 'verification_count' => 0, 'next_verification_at' => $this->clock->now()],
        );
        if ($retry && $notification->status === 'reconciliation') {
            $notification->update(['status' => 'pending', 'verification_count' => 0, 'reason' => null, 'next_verification_at' => $this->clock->now()]);
        }
        if ($notification->status !== 'pending') {
            return;
        }
        try {
            VerifyCheckout::dispatch($notification->uuid)->afterCommit();
        } catch (Throwable) {
            // Inbox is committed. The scheduled sweep can enqueue it later.
            Log::warning('Checkout notification retained for scheduled verification.', ['notification_uuid' => $notification->uuid]);
        }
    }

    public function verify(string $uuid): void
    {
        Cache::lock('checkout-notification:'.$uuid, 30)->block(5, function () use ($uuid): void {
            $notification = CheckoutNotification::query()->with('checkout')->where('uuid', $uuid)->first();
            if ($notification === null || $notification->status !== 'pending'
                || ($notification->next_verification_at !== null && $notification->next_verification_at > $this->clock->now())) {
                return;
            }
            $notification->increment('verification_count');
            $checkout = $notification->checkout;
            try {
                $payment = $this->gateway->check(new PaymentVerification($checkout->order_reference,
                    $checkout->merchant_handle, $notification->transaction_nsu, $notification->slug));
            } catch (CheckoutException) {
                $this->defer($notification, 'provider_unavailable');

                return;
            }
            if (! $payment instanceof \App\DTOs\Billing\VerifiedPayment) {
                $this->defer($notification, 'not_confirmed');

                return;
            }
            $confirmed = $this->billing->confirmCheckout($checkout, $payment);
            $notification->update(['status' => $confirmed ? 'confirmed' : 'reconciliation',
                'reason' => $confirmed ? null : 'payment_mismatch', 'next_verification_at' => null]);
        });
    }

    private function defer(CheckoutNotification $notification, string $reason): void
    {
        $exhausted = $notification->verification_count >= 12;
        $delay = min(1800, 30 * (2 ** min(10, $notification->verification_count - 1)));
        $notification->update(['status' => $exhausted ? 'reconciliation' : 'pending', 'reason' => $reason,
            'next_verification_at' => $exhausted ? null : $this->clock->now()->addSeconds($delay)]);
    }
}
