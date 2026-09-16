<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read string $email
 * @property-read string $password
 */
final class LoginRequest extends FormRequest
{
    /**
     * Open by definition: this is the endpoint you call when you have no
     * credentials yet.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],

            // No complexity rules here. Whether the password is strong enough
            // is a registration concern; refusing to even check a weak one at
            // login just tells an attacker which passwords are not worth
            // trying.
            'password' => ['required', 'string'],

            'token_name' => ['sometimes', 'string', 'max:64'],

            // Narrowing only — the role's ceiling is applied in the service,
            // so anything asked for here can reduce a token but never widen it.
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::enum(TokenAbility::class)],
        ];
    }
}
