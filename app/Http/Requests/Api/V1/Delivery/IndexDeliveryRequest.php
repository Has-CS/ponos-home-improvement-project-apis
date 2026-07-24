<?php

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as "true"/"false" text, which Laravel's
        // boolean rule rejects — normalize before validation.
        if ($this->has('has_discrepancy')) {
            $this->merge(['has_discrepancy' => filter_var($this->query('has_discrepancy'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'purchase_order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')->whereNull('deleted_at')],
            'has_discrepancy' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $v->errors(),
        ], 422));
    }
}
