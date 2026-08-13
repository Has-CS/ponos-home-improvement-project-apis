<?php

namespace App\Http\Requests\Api\V1\ChangeOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreEmergencyChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Change order fields — scope + location are mandatory for an
            // emergency (where + what, per the schema).
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'scope' => ['required', 'string'],
            'location' => ['required', 'string', 'max:200'],
            'cost_code_id' => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'urgency_id' => ['nullable', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'value' => ['nullable', 'numeric', 'min:0'],

            // On-site GC representative signature.
            'signature_image' => ['required', 'string'], // base64 / data URI (png|jpeg)
            'signer_name' => ['required', 'string', 'max:160'],
            'signer_title' => ['nullable', 'string', 'max:120'],
            'signer_company' => ['nullable', 'string', 'max:160'],
            'signer_contact' => ['nullable', 'string', 'max:120'],
            'signed_at' => ['nullable', 'date'],
            'signed_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'signed_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'device_info' => ['nullable', 'array'],
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
