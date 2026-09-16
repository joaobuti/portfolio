<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\TokenAbility;

/**
 * A request for a token, carried as scalars.
 *
 * No `fromRequest()`, for the same reason {@see \App\DTOs\Subscriptions\CreateSubscriptionData}
 * has none: a console command that mints a token for an integration must be
 * able to build this without an HTTP request in scope.
 */
final readonly class LoginData
{
    /**
     * @param  list<TokenAbility>  $requestedAbilities  Narrowing only. The role's
     *                                                  ceiling is applied on top,
     *                                                  so this can never widen a token.
     */
    public function __construct(
        public string $email,
        public string $password,
        public string $tokenName = 'api',
        public array $requestedAbilities = [],
    ) {}
}
