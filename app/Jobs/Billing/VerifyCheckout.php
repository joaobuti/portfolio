<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Services\Billing\CheckoutNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class VerifyCheckout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $notificationUuid)
    {
        $queue = config('billing.queue');
        $this->onQueue(is_string($queue) ? $queue : 'billing');
    }

    public function uniqueId(): string
    {
        return $this->notificationUuid;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(CheckoutNotificationService $service): void
    {
        $service->verify($this->notificationUuid);
    }
}
