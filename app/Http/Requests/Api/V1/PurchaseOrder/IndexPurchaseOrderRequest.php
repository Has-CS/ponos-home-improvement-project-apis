<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexPurchaseOrderRequest extends FormRequest
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
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'status_id' => ['nullable', 'integer', Rule::exists('purchase_order_statuses', 'id')->whereNull('deleted_at')],

            // Which route the order came in by. Backed by the material-request
            // link, not a column of its own.
            'source' => ['nullable', Rule::in(['standalone', 'material_request'])],
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
