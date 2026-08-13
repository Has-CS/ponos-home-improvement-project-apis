<?php

namespace App\Http\Requests\Api\V1\ProjectDeliveryAddress;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateProjectDeliveryAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics: every field `sometimes`, so an unrelated edit can't blank
     * a column it never mentioned. `city` stays non-nullable when sent —
     * clearing it would leave an address with no destination that still passes
     * the issue() guard.
     *
     * label / attention are checked in withValidator() instead of with
     * required_without: on a PATCH those rules read the PAYLOAD, not the stored
     * row, so patching one field alone would wrongly demand the other.
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:200'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:200'],
            'street_1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'street_2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'delivery_notes' => ['sometimes', 'nullable', 'string'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An address must keep SOMETHING to address it to. Evaluated against the
     * merged post-update state — stored row overlaid with whatever this request
     * actually sends — so patching `city` alone never trips it, while explicitly
     * nulling the last of the two does.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $address = $this->route('delivery_address');

            if (! $address) {
                return;
            }

            $merged = fn (string $field) => $this->has($field)
                ? $this->input($field)
                : $address->{$field};

            if (blank($merged('label')) && blank($merged('attention'))) {
                $v->errors()->add('label', 'An address needs either a label or a contact name.');
            }
        });
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
