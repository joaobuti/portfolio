<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read string $customer
 * @property-read string $plan
 */
final class StoreSubscriptionRequest extends FormRequest
{
    /**
     * A caller may only open a subscription for a customer they act for.
     *
     * Authorisation runs before validation, so the customer may not exist yet
     * at this point. An unknown uuid is refused with 403 rather than 404 on
     * purpose: answering "that customer does not exist" to an unauthenticated
     * guess turns this endpoint into a way to enumerate the customer base.
     */
    public function authorize(): bool
    {
        $customer = Customer::query()
            ->where('uuid', $this->string('customer')->toString())
            ->first();

        return $customer !== null
            && $this->user()?->can('createFor', [Subscription::class, $customer]) === true;
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
