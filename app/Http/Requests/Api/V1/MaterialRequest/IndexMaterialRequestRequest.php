<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'status_id' => ['nullable', 'integer', Rule::exists('material_request_statuses', 'id')->whereNull('deleted_at')],
            'urgency_id' => ['nullable', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],

            // Progress, separate from the approval status: "approved but not
            // fully bought" is the question a buyer actually asks.
            'ordering_status' => ['nullable', Rule::in(['not_ordered', 'partially_ordered', 'fully_ordered'])],
            'delivery_status' => ['nullable', Rule::in(['not_delivered', 'partially_delivered', 'delivered'])],
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
