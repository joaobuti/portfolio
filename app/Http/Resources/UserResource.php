<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,

            // The customer this account acts for, if any. Staff have none, and
            // a null here is meaningful rather than missing.
            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->customer === null ? null : [
                'uuid' => $this->customer->uuid,
                'name' => $this->customer->name,
            ]),

            // What this particular token may do — not what the role could do.
            // A client that reads this can hide actions it would be refused,
            // which is the difference between a usable UI and a wall of 403s.
            'abilities' => app(\App\Services\Auth\EffectiveAbilities::class)->forUser($user),

            'last_login_at' => $this->last_login_at?->format(DATE_ATOM),
        ];
    }
}
