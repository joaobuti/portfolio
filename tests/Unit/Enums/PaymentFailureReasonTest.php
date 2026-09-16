<?php

declare(strict_types=1);

use App\Enums\PaymentFailureReason;

describe('retry policy', function (): void {
    it('keeps chasing failures a later attempt could clear', function (PaymentFailureReason $reason): void {
        expect($reason->isWorthRetrying())->toBeTrue();
    })->with([
        PaymentFailureReason::Declined,
        PaymentFailureReason::InsufficientFunds,
        PaymentFailureReason::NetworkError,
    ]);

    it('gives up on failures no retry can fix', function (PaymentFailureReason $reason): void {
        // An expired card retried eight times is eight guaranteed failures,
        // eight gateway fees, and a customer nobody told to update their card.
        expect($reason->isWorthRetrying())->toBeFalse();
    })->with([
        PaymentFailureReason::ExpiredCard,
        PaymentFailureReason::InvalidRequest,
    ]);
});

describe('routing a failure to the right place', function (): void {
    it('asks the customer to act only when they can', function (): void {
        expect(PaymentFailureReason::ExpiredCard->requiresCustomerAction())->toBeTrue()
            ->and(PaymentFailureReason::NetworkError->requiresCustomerAction())->toBeFalse();
    });

    it('separates our bugs from their card', function (): void {
        // InvalidRequest should page an engineer, not email a customer.
        expect(PaymentFailureReason::InvalidRequest->isOurFault())->toBeTrue()
            ->and(PaymentFailureReason::InvalidRequest->requiresCustomerAction())->toBeFalse()
            ->and(PaymentFailureReason::Declined->isOurFault())->toBeFalse();
    });
});

describe('completeness', function (): void {
    it('answers every question for every case', function (PaymentFailureReason $reason): void {
        // A case added without updating the match arms fails here rather than
        // with an UnhandledMatchError in the middle of a charge.
        expect($reason->label())->not->toBeEmpty()
            ->and([$reason->isWorthRetrying(), $reason->requiresCustomerAction(), $reason->isOurFault()])
            ->each->toBeBool();
    })->with(PaymentFailureReason::cases());

    it('never both requires customer action and is worth retrying blindly', function (PaymentFailureReason $reason): void {
        // If the customer must act, retrying the same card cannot help.
        // Written as an implication that is always asserted rather than an
        // `if` — a conditional expect makes the cases that skip it "risky",
        // and a test that silently asserts nothing is worse than no test.
        expect(! $reason->requiresCustomerAction() || ! $reason->isWorthRetrying())->toBeTrue();
    })->with(PaymentFailureReason::cases());
});
