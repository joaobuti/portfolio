<?php

declare(strict_types=1);

namespace App\Exceptions\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Exceptions\DomainException;

final class InvalidStatusTransitionException extends DomainException
{
    private function __construct(
        private readonly SubscriptionStatus $from,
        private readonly SubscriptionStatus $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function between(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self($from, $to, sprintf(
            'A subscription cannot go from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }

    public function errorCode(): string
    {
        return 'subscription.invalid_status_transition';
    }

    /**
     * @return array{from: string, to: string, allowed: list<string>}
     */
    public function context(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'allowed' => array_map(
                static fn (SubscriptionStatus $status): string => $status->value,
                $this->from->allowedTransitions(),
            ),
        ];
    }
}
