<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * One purchase-order line added to an existing draft.
 *
 * Only field-level rules live here. The cross-entity rules — the line must
 * belong to this PO's material request, and the cumulative ordered quantity may
 * not exceed what was requested — are enforced in PurchaseOrderService, which
 * holds the aggregation. With a single line in the payload there is no index to
 * disambiguate, so its plain 422 message is unambiguous on its own.
 */
class StorePurchaseOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared field rules for a single purchase-order line — reused by the nested
     * items[] array in StorePurchaseOrderRequest, the same way
     * StoreMaterialRequestItemRequest::lineRules() serves both MR paths.
     *
     * `catalog_item_id` is NULLABLE here but still mandatory in practice: when
     * the line names a request line that already carries a catalog item,
     * PurchaseOrderService DERIVES it — asking the client for it as well gives
     * two values that can disagree, which is how an order for breakers once
     * passed against a request for water pipe. The client must still supply it
     * where there is nothing to derive from: a free-text request line, or a
     * prose-only request with no lines at all. The service rejects a line that
     * ends up with no catalog item, since pricing resolves through it.
     *
     * `unit_id` is likewise derived from the request line, so the requested and
     * ordered quantities are counted in the same unit; `unit_price` falls back
     * to the vendor's current rate.
     *
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix = ''): array
    {
        return [
            "{$prefix}material_request_item_id" => ['nullable', 'integer', Rule::exists('material_request_items', 'id')->whereNull('deleted_at')],
            "{$prefix}catalog_item_id" => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            "{$prefix}cost_code_id" => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            "{$prefix}unit_id" => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            "{$prefix}quantity_ordered" => ['required', 'numeric', 'gt:0'],
            "{$prefix}unit_price" => ['nullable', 'numeric', 'min:0'],
            "{$prefix}description" => ['nullable', 'string', 'max:255'],
        ];
    }

    public function rules(): array
    {
        return self::lineRules();
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
