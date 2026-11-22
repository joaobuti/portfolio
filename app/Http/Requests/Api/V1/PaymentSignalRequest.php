<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PaymentSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice === null || ($invoice instanceof Invoice && ($this->user()?->can('checkout', $invoice) ?? false));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'order_nsu' => [Rule::requiredIf($this->route('invoice') === null), 'string', 'max:128', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'transaction_nsu' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'invoice_slug' => ['required_without:slug', 'string', 'max:256', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'slug' => ['required_without:invoice_slug', 'string', 'max:256', 'regex:/\A[A-Za-z0-9_-]+\z/'],
        ];
    }

    /** @return array<mixed> */
    public function validationData(): array
    {
        if (strlen($this->getContent()) > 16384) {
            abort(413);
        }

        return parent::validationData();
    }
}
