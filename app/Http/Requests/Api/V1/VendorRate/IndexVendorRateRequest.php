<?php

namespace App\Http\Requests\Api\V1\VendorRate;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexVendorRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as the literal text "true"/"false",
        // which Laravel's `boolean` rule does NOT accept (only 1/0/"1"/"0"/
        // true/false) — normalize here so ?current_only=false validates.
        if ($this->has('current_only')) {
            $this->merge(['current_only' => filter_var($this->query('current_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            'current_only' => ['nullable', 'boolean'],
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
