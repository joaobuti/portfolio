<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Enums\UserRole;

describe('customer scope', function (): void {
    it('is bound to one customer', function (): void {
        expect(UserRole::Customer->accessesAnyCustomer())->toBeFalse()
            ->and(UserRole::Customer->requiresCustomer())->toBeTrue();
    });

    it('can read plans and manage its own subscriptions', function (): void {
        expect(UserRole::Customer->defaultAbilities())->toBe([
            TokenAbility::PlansRead,
            TokenAbility::SubscriptionsRead,
            TokenAbility::SubscriptionsWrite,
        ]);
    });
});

describe('staff scope', function (): void {
    it('reaches every customer and needs none of its own', function (): void {
        expect(UserRole::Staff->accessesAnyCustomer())->toBeTrue()
            ->and(UserRole::Staff->requiresCustomer())->toBeFalse();
    });

    it('holds every ability there is', function (): void {
        expect(UserRole::Staff->defaultAbilities())->toBe(TokenAbility::cases());
    });
});

describe('the ceiling', function (): void {
    it('never exceeds the full set of abilities', function (UserRole $role): void {
        // The guarantee the whole authorisation model rests on: a role cannot
        // hand out a permission that does not exist. Compared with a strict
        // in_array rather than array_diff, which casts to string and throws
        // on a backed enum.
        foreach ($role->defaultAbilities() as $ability) {
            expect(in_array($ability, TokenAbility::cases(), strict: true))->toBeTrue();
        }
    })->with(UserRole::cases());
});

describe('every role answers every question', function (): void {
    it('has a defined scope and ceiling', function (UserRole $role): void {
        // A role added without updating the match arms fails here rather than
        // at runtime with an UnhandledMatchError in the middle of a login.
        expect($role->defaultAbilities())->toBeArray()
            ->and($role->requiresCustomer())->toBe(! $role->accessesAnyCustomer());
    })->with(UserRole::cases());
});
