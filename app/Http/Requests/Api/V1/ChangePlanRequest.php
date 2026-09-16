<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\AuthorizesSubscriptionRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read string $plan
 */
final class ChangePlanRequest extends FormRequest
{
    use AuthorizesSubscriptionRoute;

    /**
     * Both the preview and the real change authorise as `update`. A preview
     * writes nothing, but it reveals what a customer pays — which is exactly
     * as private as the change itself.
     */
    public function authorize(): bool
    {
        return $this->authorizeSubscription('update');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                'string',
                Rule::exists('plans', 'code')->where('is_active', true),
            ],
            'apply_immediately' => ['sometimes', 'boolean'],

            // Only the shape is validated here. Whether the code exists and
            // applies is the pricing service's rule to own — checking it in
            // two places is how the two answers start to disagree.
            'coupon' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.exists' => 'The selected plan is not available.',
        ];
    }
}
