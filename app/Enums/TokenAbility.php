<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The scopes a personal access token can carry.
 *
 * ## Why an enum and not strings
 *
 * Sanctum's abilities are free-form strings, which means a typo in
 * `tokenCan('subscriptions:wirte')` fails open — it returns false, the check
 * passes for nobody, and nobody notices until a customer reports they cannot
 * do something. Naming them here turns that into a compile-time-ish error and
 * gives one place to enumerate what a token may ever contain.
 *
 * ## Granularity
 *
 * Read and write are separated because they have genuinely different blast
 * radii: a reporting integration that only ever lists subscriptions should not
 * hold a token that can cancel one. They are not separated per-endpoint,
 * because an ability nobody can explain is an ability nobody sets correctly.
 */
enum TokenAbility: string
{
    case PlansRead = 'plans:read';
    case SubscriptionsRead = 'subscriptions:read';
    case SubscriptionsWrite = 'subscriptions:write';

    /**
     * @param  list<self>  $abilities
     * @return list<string>
     */
    public static function toValues(array $abilities): array
    {
        return array_map(
            static fn (self $ability): string => $ability->value,
            $abilities,
        );
    }

    /**
     * Resolve caller-supplied strings into abilities, dropping anything
     * unrecognised.
     *
     * Dropping rather than throwing: an unknown ability is not a request to
     * fail, it is a request for a permission that does not exist, and the
     * safest reading of that is "you do not get it". The result is then
     * intersected with the role's ceiling by {@see \App\Services\Auth\AuthenticationService},
     * so this method can never widen a token.
     *
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        return array_values(array_filter(array_map(self::tryFrom(...), $values)));
    }
}
