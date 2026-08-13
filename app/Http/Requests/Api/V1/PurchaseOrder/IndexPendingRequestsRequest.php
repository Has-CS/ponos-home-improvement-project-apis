<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Filters for the buyer's queue of approved material requests awaiting a PO.
 */
class IndexPendingRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as the literal text "true"/"false", which
        // Laravel's `boolean` rule rejects — normalize so ?needs_structuring=true
        // validates. Same treatment as IndexCatalogItemRequest.
        if ($this->has('needs_structuring')) {
            $this->merge([
                'needs_structuring' => filter_var($this->query('needs_structuring'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'needs_structuring' => ['nullable', 'boolean'],
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
