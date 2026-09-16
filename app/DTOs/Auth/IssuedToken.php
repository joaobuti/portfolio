<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\TokenAbility;
use DateTimeImmutable;

/**
 * A freshly minted access token.
 *
 * The plain-text value exists on this object and **nowhere else, ever again** —
 * only its hash is stored. That is the whole security property of a bearer
 * token scheme, and it is why this DTO is returned straight to the HTTP layer
 * and never logged, cached or persisted.
 *
 * Framework-free by the architecture test, which is convenient here: an object
 * that cannot reach a logger cannot accidentally be written to one.
 */
final readonly class IssuedToken
{
    /**
     * @param  list<TokenAbility>  $abilities
     */
    public function __construct(
        public string $plainTextToken,
        public array $abilities,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * @return list<string>
     */
    public function abilityValues(): array
    {
        return TokenAbility::toValues($this->abilities);
    }
}
