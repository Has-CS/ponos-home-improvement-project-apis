<?php

namespace App\Http\Requests\Api\V1\ProjectGeneralContractor;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PATCH: every field optional, but `sometimes|required` on the three the store
 * request demands, so a partial update cannot blank out the name or address a
 * change-order document has to print.
 */
class UpdateProjectGeneralContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'street_1' => ['sometimes', 'required', 'string', 'max:200'],
            'street_2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_primary' => ['sometimes', 'boolean'],
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
