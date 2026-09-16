<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\IssuedToken;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The Sanctum-backed {@see TokenIssuer}.
 *
 * This is the only class in the application that knows Sanctum exists, which
 * is the point: `use Laravel\Sanctum` appears here and in the User model's
 * trait, and nowhere else.
 */
final readonly class SanctumTokenIssuer implements TokenIssuer
{
    /**
     * @param  list<TokenAbility>  $abilities
     */
    public function issue(User $user, string $name, array $abilities): IssuedToken
    {
        $expiresAt = $this->expiry();

        $token = $user->createToken(
            $name,
            TokenAbility::toValues($abilities),
            $expiresAt,
        );

        return new IssuedToken(
            plainTextToken: $token->plainTextToken,
            abilities: $abilities,
            expiresAt: $expiresAt?->toDateTimeImmutable(),
        );
    }

    public function revokeCurrent(User $user): bool
    {
        // Typed as `object|null` deliberately. Sanctum's docblock promises
        // `PersonalAccessToken`, but a session-authenticated caller really
        // holds a TransientToken — so trusting that annotation would make the
        // check below statically "always true" and silently delete the case it
        // exists to catch.
        /** @var object|null $token */
        $token = $user->currentAccessToken();

        // A TransientToken has no row to delete. Reporting false rather than
        // pretending is what lets the controller answer honestly.
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        return $token->delete() === true;
    }

    /**
     * Absolute expiry, from Sanctum's own config so there is one dial rather
     * than two that can disagree.
     *
     * Null means the token never expires. That is Sanctum's default and it is
     * a poor one for a billing API — `SANCTUM_EXPIRATION` is set in
     * .env.example precisely so this project does not ship with it.
     */
    private function expiry(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return is_numeric($minutes) ? Carbon::now()->addMinutes((int) $minutes) : null;
    }
}
