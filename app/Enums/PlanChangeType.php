<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Direction of a plan change.
 *
 * Whether it took effect immediately or was queued for the end of the period
 * is not modelled here — it is derivable from the change record's
 * `effective_at`, and a flag that duplicates data already in the row is a
 * second source of truth waiting to disagree with the first.
 */
enum PlanChangeType: string
{
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Reversion = 'reversion';

    public function label(): string
    {
        return match ($this) {
            self::Upgrade => 'Upgrade',
            self::Downgrade => 'Downgrade',
            self::Reversion => 'Reversão por falta de pagamento',
        };
    }
}
