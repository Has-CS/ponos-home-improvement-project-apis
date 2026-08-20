<?php

namespace App\Http\Requests\Api\V1\Rfq;

use App\Http\Requests\Api\V1\CatalogItem\SearchCatalogItemRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Type-ahead search over the catalog, used when adding an RFQ line.
 *
 * Shares its query floor, result cap and filters with the material-request
 * picker via SearchCatalogItemRequest::baseRules(). Unlike the purchase-order
 * picker, no project_id/vendor_id params are needed here: the route is nested
 * under an existing RFQ, which already carries both (project nullable,
 * vendor always set) — the controller derives them from it.
 */
class SearchRfqCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return SearchCatalogItemRequest::baseRules();
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
