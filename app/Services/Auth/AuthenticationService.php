<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\IssuedToken;
use App\DTOs\Auth\LoginData;
use App\Enums\TokenAbility;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Turns credentials into a token.
 *
 * ## Responsibilities, and the ones deliberately not here
 *
 * This class verifies a password and decides what a token may contain. It does
 * not know how a token is represented — {@see TokenIssuer} does — and it does
 * not know what an HTTP request looks like. Both are constructor dependencies
 * on abstractions, which is what makes the whole thing testable with two fakes
 * and no database.
 *
 * ## The timing attack, and why the dummy hash is there
 *
 * A naive implementation returns early when the email is unknown, and that
 * early return is measurable: an unknown address answers in microseconds, a
 * known one in the ~100ms bcrypt takes. An attacker times the endpoint and
 * enumerates your customer list without guessing a single password. Hashing
 * against a throwaway hash in the not-found branch keeps both paths the same
 * shape.
 */
final readonly class AuthenticationService
{
    /**
     * A valid bcrypt hash of a value nobody knows. Only ever compared against,
     * never matched — its purpose is to consume the same time a real
     * comparison would.
     */
    private const string DUMMY_HASH = '$2y$12$mMOZ8vG1xLLYyBKMuVfOU.SxHNrCsuMhwLYQ8gJd2QeiHM/CIiKmm';

    public function __construct(
        private TokenIssuer $tokens,
        private Hasher $hasher,
        private Clock $clock,
    ) {}

    /**
     * @throws InvalidCredentialsException
     */
    public function authenticate(LoginData $data): IssuedToken
    {
        $user = $this->verifyCredentials($data->email, $data->password);
        if ($user->demo_workspace_id !== null) {
            throw new InvalidCredentialsException;
        }

        return $this->tokens->issue($user, $data->tokenName, $this->grantableAbilities($user, $data->requestedAbilities));
    }

    public function verifyCredentials(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Burn the same time a real comparison would before refusing.
            // See the class docblock: the early return is the timing leak.
            $this->hasher->check($password, self::DUMMY_HASH);

            throw new InvalidCredentialsException;
        }

        if (! $this->hasher->check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        $user->forceFill(['last_login_at' => $this->clock->now()])->save();

        return $user;
    }

    /**
     * What the token actually gets: the intersection of what was asked for and
     * what the role permits.
     *
     * Intersection, never union. A client asking for an ability its role does
     * not have receives a token without it rather than an error — the request
     * is honoured as far as it legitimately can be, and privilege escalation
     * is impossible by construction rather than by a check somebody might
     * forget to add to a new endpoint.
     *
     * @param  list<TokenAbility>  $requested
     * @return list<TokenAbility>
     */
    private function grantableAbilities(User $user, array $requested): array
    {
        $ceiling = $user->role->defaultAbilities();

        if ($requested === []) {
            return $ceiling;
        }

        // Not `array_intersect`: it compares by string cast, and a backed enum
        // throws when cast to string. The strict `in_array` also compares the
        // enum instances themselves rather than their values, which is what we
        // actually mean.
        return array_values(array_filter(
            $requested,
            static fn (TokenAbility $ability): bool => in_array($ability, $ceiling, strict: true),
        ));
    }
}
