<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\IssuedToken;
use App\Enums\TokenAbility;
use App\Models\User;

/**
 * Mints and revokes access tokens.
 *
 * ## Why this interface exists
 *
 * Not speculation — it is the seam that keeps Sanctum out of
 * {@see AuthenticationService}. Credential verification and token minting are
 * two responsibilities with two reasons to change: the first changes when the
 * password policy does, the second when the token scheme does. Swapping
 * Sanctum for signed JWTs should touch one class, and this interface is what
 * makes that true.
 *
 * It is also what lets the authentication tests assert "a token was issued
 * with these abilities" without a database round trip through
 * `personal_access_tokens`.
 *
 * Kept to two methods on purpose. A fat `AuthService` that also registered
 * users, reset passwords and verified emails would force every implementer to
 * care about all of it — the interface segregation principle is the reason
 * this is narrow rather than convenient. A `revokeAll()` for compromised
 * accounts is an obvious third, and is deliberately absent until something
 * calls it: an interface method with no caller is a guess about the future
 * that every implementer still has to satisfy.
 */
interface TokenIssuer
{
    /**
     * @param  list<TokenAbility>  $abilities
     */
    public function issue(User $user, string $name, array $abilities): IssuedToken;

    /**
     * Revoke the token the current request authenticated with.
     *
     * Returns false when there is nothing to revoke — a session-authenticated
     * caller, or a token already deleted by a concurrent logout. Not an error:
     * "you are logged out" is the desired end state either way.
     */
    public function revokeCurrent(User $user): bool;
}
