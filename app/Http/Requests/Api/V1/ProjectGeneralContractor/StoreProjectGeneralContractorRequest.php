<?php

namespace App\Http\Requests\Api\V1\ProjectGeneralContractor;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Stricter than the delivery-address equivalent, deliberately.
 *
 * A ship-to destination takes several shapes (a bare site label, a named contact
 * at a building), so that request only demands a city plus something to address
 * it to. A GC is a company on a semi-contractual document: it has to print a
 * name and a real postal address, or the "To" block on a change order is not
 * usable. Hence name / street_1 / city are all required.
 */
class StoreProjectGeneralContractorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'street_1' => ['required', 'string', 'max:200'],
            'street_2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_primary' => ['nullable', 'boolean'],
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
