<?php

namespace App\Http\Requests\Api\V1\ProjectDeliveryAddress;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreProjectDeliveryAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately permissive, because a destination takes more than one shape:
     * a site address leads with a label and a street, while a contact-led one
     * leads with a person at a company location and has no street at all.
     *
     * So the only hard requirements are `city` plus SOMETHING to address it to —
     * a label, a contact name, or both. Everything else is optional, and
     * PurchaseOrder::shipToLines() drops whatever is missing rather than
     * printing blank lines.
     */
    public function rules(): array
    {
        return [
            // required_without in both directions: either names the recipient,
            // and an address with neither is just a bare city.
            'label' => ['required_without:attention', 'nullable', 'string', 'max:200'],
            'attention' => ['required_without:label', 'nullable', 'string', 'max:200'],
            'street_1' => ['nullable', 'string', 'max:200'],
            'street_2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'delivery_notes' => ['nullable', 'string'],
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
