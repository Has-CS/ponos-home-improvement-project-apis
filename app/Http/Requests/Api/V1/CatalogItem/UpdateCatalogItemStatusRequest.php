<?php

namespace App\Http\Requests\Api\V1\CatalogItem;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Retire or restore a catalog item.
 *
 * A dedicated request, mirroring UpdateVendorStatusRequest: `is_active` is
 * REQUIRED here, so the caller has to state the intended state rather than
 * relying on a toggle. The flag is also settable through the ordinary create
 * and update, exactly as a vendor's is — this endpoint just makes the intent
 * explicit and gives the UI a single-purpose action to bind to.
 */
class UpdateCatalogItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level permission:edit_pricing handles authorization
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
