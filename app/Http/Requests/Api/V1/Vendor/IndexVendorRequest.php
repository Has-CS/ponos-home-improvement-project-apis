<?php

namespace App\Http\Requests\Api\V1\Vendor;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as the literal text "true"/"false",
        // which Laravel's `boolean` rule does NOT accept (only 1/0/"1"/"0"/
        // true/false) — normalize here so ?is_active=false validates.
        if ($this->has('is_active')) {
            $this->merge(['is_active' => filter_var($this->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['name', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
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
