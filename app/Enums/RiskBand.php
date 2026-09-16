<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How risky a customer looks, as scored by the Python risk worker.
 *
 * The thresholds live in the worker — it is the service that owns the model —
 * and this enum only names the buckets it reports. Duplicating the boundaries
 * here would create a second definition that drifts the first time the model
 * is tuned.
 */
enum RiskBand: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Whether a human should look at this account.
     */
    public function warrantsReview(): bool
    {
        return $this === self::High;
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Baixo risco',
            self::Medium => 'Risco moderado',
            self::High => 'Alto risco',
        };
    }
}
