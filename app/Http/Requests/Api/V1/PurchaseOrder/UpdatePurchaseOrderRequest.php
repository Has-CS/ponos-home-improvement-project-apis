<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_delivery_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],

            // Re-point the destination while the PO is still a draft.
            // PurchaseOrderService::update() re-resolves the whole ship_to_*
            // snapshot from this id rather than filling it directly, so the
            // printed block can never drift from the FK. An explicit null
            // clears the destination; omitting the key leaves it untouched.
            'ship_to_address_id' => ['sometimes', 'nullable', 'integer', Rule::exists('project_delivery_addresses', 'id')->whereNull('deleted_at')],
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
