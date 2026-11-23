<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\TokenAbility;
use App\Models\User;
use Laravel\Sanctum\TransientToken;

final readonly class EffectiveAbilities
{
    public function isSession(User $user): bool
    {
        /** @var object|null $token */
        $token = $user->currentAccessToken();

        return $token === null || $token instanceof TransientToken;
    }

    /** @return list<string> */
    public function forUser(User $user): array
    {
        $ceiling = $user->role->defaultAbilities();
        if ($this->isSession($user) && $user->role->accessesAnyCustomer()) {
            $ceiling = [TokenAbility::PlansRead, TokenAbility::SubscriptionsRead,
                TokenAbility::InvoicesRead, TokenAbility::CustomersRead];
        }

        return TokenAbility::toValues(array_values(array_filter($ceiling,
            fn (TokenAbility $ability): bool => $this->isSession($user) || $user->tokenCan($ability->value))));
    }
}
