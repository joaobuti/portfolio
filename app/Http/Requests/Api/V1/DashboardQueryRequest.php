<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DashboardQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'customer' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
