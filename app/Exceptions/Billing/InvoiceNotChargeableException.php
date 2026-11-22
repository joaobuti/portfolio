<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Exceptions\DomainException;

/**
 * An attempt was made to charge an invoice that must not be charged.
 *
 * This is the guard against the most expensive bug this module can have. It
 * throws rather than returning false because every caller reaching it has a
 * logic error — a retried job that ignored the status, a support tool charging
 * a paid invoice — and silently doing nothing would hide it.
 */
final class InvoiceNotChargeableException extends DomainException
{
    private function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadySettled(string $status): self
    {
        return new self(
            'already_settled',
            "Invoice is [{$status}] and can no longer be charged.",
        );
    }

    /**
     * The pricing service was down when this invoice was priced, so it carries
     * no tax. Charging it would bill an amount no rule can reproduce.
     */
    public static function pricingDegraded(): self
    {
        return new self(
            'pricing_degraded',
            'Invoice was priced without tax and cannot be charged. Re-issue it once pricing is available.',
        );
    }

    public static function notDue(): self
    {
        return new self('not_due', 'The next collection attempt is not due yet.');
    }

    public function errorCode(): string
    {
        return 'invoice.not_chargeable';
    }

    /**
     * @return array{reason: string}
     */
    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
