<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\AuthorizesSubscriptionRoute;
use Illuminate\Foundation\Http\FormRequest;

final class CancelSubscriptionRequest extends FormRequest
{
    use AuthorizesSubscriptionRoute;

    public function authorize(): bool
    {
        return $this->authorizeSubscription('delete');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'immediately' => ['sometimes', 'boolean'],
        ];
    }
}
