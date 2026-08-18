<?php

declare(strict_types=1);

namespace App\DTOs\Subscriptions;

use DateTimeImmutable;

/**
 * Everything needed to open a subscription, carried as scalars.
 *
 * Scalars rather than models on purpose: this object has to survive being
 * serialised onto a queue, and a DTO that drags an Eloquent model with it
 * either fails to serialise or quietly resurrects stale attributes. The
 * service resolves the models, which also keeps "does this plan exist?" as one
 * question answered in one place.
 *
 * No `fromRequest()` here either. A DTO that knows how to read an HTTP request
 * cannot be built from a console command or a queue job without dragging the
 * HTTP layer along — and `tests/Arch.php` would fail the build for importing
 * `Illuminate` into the pure core. Controllers do the translating.
 */
final readonly class CreateSubscriptionData
{
    public function __construct(
        public string $customerUuid,
        public string $planCode,
        public bool $withTrial = true,
        public ?DateTimeImmutable $startsAt = null,
    ) {}
}
