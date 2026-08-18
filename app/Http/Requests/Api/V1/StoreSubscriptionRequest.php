<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read string $customer
 * @property-read string $plan
 */
final class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Authorisation arrives with the auth module. Until then this is honest
     * about being open rather than pretending to check something.
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
            'customer' => ['required', 'uuid', Rule::exists('customers', 'uuid')],
            'plan' => [
                'required',
                'string',
                // Existence and availability are one rule, not two: a plan that
                // exists but is retired is just as invalid here as one that
                // never existed, and saying so in a single message avoids
                // telling a caller which retired plan codes we have.
                Rule::exists('plans', 'code')->where('is_active', true),
            ],
            'with_trial' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.exists' => 'The selected plan is not available for new subscriptions.',
        ];
    }
}
