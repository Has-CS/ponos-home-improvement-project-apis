<?php

namespace App\Http\Requests\Api\V1\CatalogItem;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Type-ahead search over the catalog, used when adding a material-request line.
 *
 * Deliberately separate from IndexCatalogItemRequest: that one backs the
 * pricing-gated admin list (sorting, is_custom filter, full pagination), this one
 * backs a keystroke-driven picker and is reachable by field roles.
 */
class SearchCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rules every catalog type-ahead shares, whichever module it serves.
     *
     * Extracted so the purchase-order picker
     * (SearchPurchaseOrderCatalogItemRequest) inherits the same query floor,
     * result cap and filters rather than restating them — the same sharing
     * StoreMaterialRequestRequest already does with
     * StoreMaterialRequestItemRequest::lineRules().
     *
     * @return array<string,mixed>
     */
    public static function baseRules(): array
    {
        return [
            // min:2 stops a single keystroke from returning the whole catalog.
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'trade_category_id' => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'catalog_item_type_id' => ['nullable', 'integer', Rule::exists('catalog_item_types', 'id')->whereNull('deleted_at')],
        ];
    }

    public function rules(): array
    {
        return self::baseRules();
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
