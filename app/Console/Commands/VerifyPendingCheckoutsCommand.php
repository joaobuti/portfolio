<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\VerifyCheckout;
use App\Models\CheckoutNotification;
use App\Support\Clock;
use Illuminate\Console\Command;

final class VerifyPendingCheckoutsCommand extends Command
{
    protected $signature = 'checkouts:verify';

    protected $description = 'Recover pending InfinitePay notification verification';

    public function handle(Clock $clock): int
    {
        CheckoutNotification::query()->where('status', 'pending')
            ->where('next_verification_at', '<=', $clock->now())->lazyById()
            ->each(static function (CheckoutNotification $notification): void {
                VerifyCheckout::dispatch($notification->uuid);
            });

        return self::SUCCESS;
    }
}
