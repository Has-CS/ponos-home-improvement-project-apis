<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use App\Http\Requests\Api\V1\CatalogItem\SearchCatalogItemRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Type-ahead search over the catalog, used when adding a purchase-order line.
 *
 * Shares its query floor, result cap and filters with the material-request
 * picker via SearchCatalogItemRequest::baseRules(), and adds the two things a
 * buyer's search needs that a foreman's does not.
 */
class SearchPurchaseOrderCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...SearchCatalogItemRequest::baseRules(),

            // Required because purchase orders are top-level while the catalog
            // search is project-scoped (global items plus that project's custom
            // ones). The buyer always has this: it comes back on the material
            // request, and on /purchase-orders/pending-requests.
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // Optional so the picker still works before a vendor is chosen —
            // results then simply carry no pricing.
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
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
