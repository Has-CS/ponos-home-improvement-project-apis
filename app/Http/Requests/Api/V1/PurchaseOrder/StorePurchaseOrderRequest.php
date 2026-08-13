<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'material_request_id' => ['required', 'integer', Rule::exists('material_requests', 'id')->whereNull('deleted_at')],
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],

            // Optional here, mandatory at issue (PurchaseOrderService::issue()).
            // Omitting it falls back to the project's primary address. The
            // "belongs to this project" check lives in the service, which is
            // where the material request — and therefore the project — is
            // resolved; this rule only proves the row exists.
            'ship_to_address_id' => ['nullable', 'integer', Rule::exists('project_delivery_addresses', 'id')->whereNull('deleted_at')],

            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_request_item_id' => ['nullable', 'integer', Rule::exists('material_request_items', 'id')->whereNull('deleted_at')],
            'items.*.catalog_item_id' => ['required', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            'items.*.cost_code_id' => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'items.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->filled('material_request_id')) {
                return;
            }

            $mr = MaterialRequest::with('status')->find($this->input('material_request_id'));
            $code = $mr?->status?->code
                ?? MaterialRequestStatus::whereKey($mr?->material_request_status_id)->value('code');

            if (! in_array($code, ['approved', 'ordered'], true)) {
                $v->errors()->add('material_request_id', 'A purchase order can only be created from an approved material request.');
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
