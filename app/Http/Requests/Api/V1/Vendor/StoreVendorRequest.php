<?php

namespace App\Http\Requests\Api\V1\Vendor;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],

            // The trades this vendor supplies — ids from the existing
            // trade_categories lookup, the taxonomy catalog items already use.
            // A soft-deleted trade cannot be assigned, and duplicate ids are a
            // clean 422 rather than a silent de-duplication.
            'trade_category_ids' => ['nullable', 'array'],
            'trade_category_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('trade_categories', 'id')->whereNull('deleted_at'),
            ],
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
