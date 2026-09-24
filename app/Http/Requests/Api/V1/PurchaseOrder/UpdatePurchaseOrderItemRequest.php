<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * A PATCH against one line of a draft purchase order: absent keys are left
 * alone, so a client can send only what changed.
 *
 * Same `sometimes, required` shape as UpdateMaterialRequestItemRequest —
 * a field may be omitted, but not sent as null when the column needs a value.
 * Whether an edit re-resolves the price is decided in PurchaseOrderService: only
 * a changed `catalog_item_id` or an explicit `unit_price` reprices the line, so
 * fixing a quantity never repricing against a rate that has moved since.
 */
class UpdatePurchaseOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Re-pointing the line at a different requested line is allowed while
            // the PO is a draft; the service still proves it belongs to this PO's
            // material request. Explicit null unlinks it.
            'material_request_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('material_request_items', 'id')->whereNull('deleted_at')],

            // Swapping the catalog item re-resolves the vendor price for the new
            // item, mirroring how MR re-derives its trade category on a swap.
            'catalog_item_id' => ['sometimes', 'required', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],

            'cost_code_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'unit_id' => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'quantity_ordered' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
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
