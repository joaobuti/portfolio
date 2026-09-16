<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Auth\IssuedToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one and only time the plain-text token is ever rendered.
 *
 * @mixin IssuedToken
 */
final class IssuedTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->plainTextToken,
            'token_type' => 'Bearer',
            'abilities' => $this->abilityValues(),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
        ];
    }
}
