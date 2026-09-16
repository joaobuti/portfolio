<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a charge did not go through.
 *
 * ## Why this is modelled and not a free-text gateway string
 *
 * Every gateway spells its decline codes differently, and dunning policy turns
 * on the *kind* of failure, not the wording. A card that expired needs the
 * customer to act; a network blip needs a retry in ninety seconds. Storing the
 * raw string would mean every policy decision reparsing a vendor's vocabulary.
 *
 * The adapter maps its vendor's codes onto these cases and keeps the raw string
 * alongside for support to read. That mapping is the adapter's job precisely so
 * that swapping gateways does not change what dunning does.
 */
enum PaymentFailureReason: string
{
    /** The issuer said no, without telling us more. */
    case Declined = 'declined';

    /** The issuer said no, and said why. Retrying tomorrow may well work. */
    case InsufficientFunds = 'insufficient_funds';

    /** The card is past its expiry. Only the customer can fix this. */
    case ExpiredCard = 'expired_card';

    /** We never got an answer. Says nothing about the card. */
    case NetworkError = 'network_error';

    /** The gateway rejected the request itself — our bug, not the customer's. */
    case InvalidRequest = 'invalid_request';

    /**
     * Whether retrying the same card could plausibly succeed later.
     *
     * This is the whole of the dunning policy's input. An expired card retried
     * eight times is eight guaranteed failures, eight gateway fees, and a
     * customer wondering why nobody told them to update their card.
     */
    public function isWorthRetrying(): bool
    {
        return match ($this) {
            self::Declined, self::InsufficientFunds, self::NetworkError => true,
            self::ExpiredCard, self::InvalidRequest => false,
        };
    }

    /**
     * Whether the customer has to do something before it can succeed.
     *
     * Drives which dunning email goes out — "we'll try again" versus "please
     * update your card".
     */
    public function requiresCustomerAction(): bool
    {
        return match ($this) {
            self::ExpiredCard => true,
            self::Declined, self::InsufficientFunds, self::NetworkError, self::InvalidRequest => false,
        };
    }

    /**
     * Whether the failure is ours rather than the customer's.
     *
     * Kept separate from the two questions above because it routes elsewhere:
     * this one should page an engineer, not email a customer.
     */
    public function isOurFault(): bool
    {
        return $this === self::InvalidRequest;
    }

    public function label(): string
    {
        return match ($this) {
            self::Declined => 'Pagamento recusado',
            self::InsufficientFunds => 'Saldo insuficiente',
            self::ExpiredCard => 'Cartão expirado',
            self::NetworkError => 'Falha de comunicação',
            self::InvalidRequest => 'Requisição inválida',
        };
    }
}
