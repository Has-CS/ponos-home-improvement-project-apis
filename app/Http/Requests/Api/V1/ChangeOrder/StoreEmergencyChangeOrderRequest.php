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
            // Free text, one entry per line — see ChangeOrder::inclusionList().
            'inclusions' => ['nullable', 'string'],
            'exclusions' => ['nullable', 'string'],
            'location' => ['required', 'string', 'max:200'],
            // Existence only. That it belongs to THIS project is checked in
            // ChangeOrderService, which is the layer that knows the project.
            'general_contractor_id' => ['nullable', 'integer', Rule::exists('project_general_contractors', 'id')->whereNull('deleted_at')],
            'cost_code_id' => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'urgency_id' => ['nullable', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],

            // PROHIBITED, not merely absent, and the difference matters. Pricing an
            // emergency change order is the project manager's job, not the job of
            // the person holding the device while a GC representative signs — so
            // the capture endpoint must not accept a figure at all.
            //
            // Simply dropping the key would rely on Laravel discarding it from
            // validated(), which means a field app that kept sending a price would
            // get a 201 with the price SILENTLY thrown away. A prohibited rule says
            // so out loud instead. Same idiom as StoreLookupRequest.
            'value' => ['prohibited'],

            // On-site GC representative signature.
            'signature_image' => ['required', 'string'], // base64 / data URI (png|jpeg)

            // The signer MUST be a stored representative picked from the dropdown;
            // the signer_* columns are filled from it. ChangeOrderService checks it
            // belongs to the change order's GC.
            'gc_representative_id' => ['required', 'integer', Rule::exists('gc_representatives', 'id')->whereNull('deleted_at')],

            // No longer typed by hand. `prohibited` rather than dropped from the
            // rules, so a stale client gets a 422 instead of its typed details
            // being silently discarded — same reasoning as `value` above.
            'signer_name' => ['prohibited'],
            'signer_title' => ['prohibited'],
            'signer_company' => ['prohibited'],
            'signer_contact' => ['prohibited'],
            'signed_at' => ['nullable', 'date'],
            'signed_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'signed_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location_note' => ['nullable', 'string', 'max:200'],
            'device_info' => ['nullable', 'array'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'value.prohibited' => 'An emergency change order is captured without a price. A project manager prices it afterwards.',
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
