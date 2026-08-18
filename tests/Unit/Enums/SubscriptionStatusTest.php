<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;

it('allows the transitions the business defined', function (
    SubscriptionStatus $from,
    SubscriptionStatus $to,
): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    'trial converts' => [SubscriptionStatus::Trialing, SubscriptionStatus::Active],
    'trial abandoned' => [SubscriptionStatus::Trialing, SubscriptionStatus::Canceled],
    'charge fails' => [SubscriptionStatus::Active, SubscriptionStatus::PastDue],
    'charge recovers' => [SubscriptionStatus::PastDue, SubscriptionStatus::Active],
    'dunning gives up' => [SubscriptionStatus::PastDue, SubscriptionStatus::Canceled],
    'canceled runs out its period' => [SubscriptionStatus::Canceled, SubscriptionStatus::Expired],
]);

it('forbids the ones it does not', function (
    SubscriptionStatus $from,
    SubscriptionStatus $to,
): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'no going back to a trial' => [SubscriptionStatus::Active, SubscriptionStatus::Trialing],
    'expired is the end' => [SubscriptionStatus::Expired, SubscriptionStatus::Active],
    'canceled does not resurrect' => [SubscriptionStatus::Canceled, SubscriptionStatus::Active],
]);

it('never allows a status to transition to itself', function (SubscriptionStatus $status): void {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(SubscriptionStatus::cases());

it('grants access while a customer is trialing, active or in dunning', function (): void {
    expect(SubscriptionStatus::Trialing->grantsAccess())->toBeTrue()
        ->and(SubscriptionStatus::Active->grantsAccess())->toBeTrue()
        // Deliberate: one failed card should not lock a paying customer out.
        ->and(SubscriptionStatus::PastDue->grantsAccess())->toBeTrue()
        ->and(SubscriptionStatus::Canceled->grantsAccess())->toBeFalse()
        ->and(SubscriptionStatus::Expired->grantsAccess())->toBeFalse();
});

it('treats exactly one status as terminal', function (): void {
    $terminal = array_filter(
        SubscriptionStatus::cases(),
        static fn (SubscriptionStatus $status): bool => $status->isTerminal(),
    );

    expect($terminal)->toHaveCount(1)
        ->and(reset($terminal))->toBe(SubscriptionStatus::Expired);
});
