<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\CheckoutNotification;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\PaymentCheckout;
use App\Models\PlanChangeQuote;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class DemoPruner
{
    public function __construct(private DemoConfiguration $configuration, private Clock $clock) {}

    public function prune(): int
    {
        $this->configuration->ensureEnabled();

        /** @var int $count */
        $count = Cache::lock('demo:provision', 30)->block(10, function (): int {
            $expired = DemoWorkspace::query()->where('expires_at', '<=', $this->clock->now())->limit(100)->get();
            foreach ($expired as $workspace) {
                DB::transaction(function () use ($workspace): void {
                    $customerIds = Customer::query()->where('demo_workspace_id', $workspace->id)->pluck('id');
                    $subscriptionIds = Subscription::query()->whereIn('customer_id', $customerIds)->pluck('id');
                    $invoiceIds = Invoice::query()->whereIn('customer_id', $customerIds)->pluck('id');
                    $checkoutIds = PaymentCheckout::query()->whereIn('invoice_id', $invoiceIds)->pluck('id');
                    CheckoutNotification::query()->whereIn('payment_checkout_id', $checkoutIds)->delete();
                    PaymentCheckout::query()->whereIn('id', $checkoutIds)->delete();
                    PlanChangeQuote::query()->whereIn('subscription_id', $subscriptionIds)->delete();
                    Invoice::query()->whereIn('id', $invoiceIds)->delete();
                    Subscription::query()->whereIn('id', $subscriptionIds)->delete();
                    User::query()->where('demo_workspace_id', $workspace->id)->delete();
                    Customer::query()->whereIn('id', $customerIds)->delete();
                    $workspace->delete();
                });
            }

            return $expired->count();
        });

        return $count;
    }
}
