<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Plan;
use App\Services\Subscriptions\PlanCatalog;

/**
 * Keeps the cached plan catalogue honest.
 *
 * Every write path to a plan — admin panel, console command, seeder, tinker —
 * goes through Eloquent, so hanging invalidation off the model's own lifecycle
 * is the only version of this that cannot be forgotten at a call site.
 */
final readonly class PlanObserver
{
    public function __construct(private PlanCatalog $catalog) {}

    public function saved(Plan $plan): void
    {
        $this->catalog->flush();
    }

    public function deleted(Plan $plan): void
    {
        $this->catalog->flush();
    }
}
