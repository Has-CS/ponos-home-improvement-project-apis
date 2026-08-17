<?php

namespace App\Http\Requests\Api\V1\ChangeOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'scope' => ['sometimes', 'nullable', 'string'],
            'inclusions' => ['sometimes', 'nullable', 'string'],
            'exclusions' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'general_contractor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('project_general_contractors', 'id')->whereNull('deleted_at')],
            'cost_code_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'urgency_id' => ['sometimes', 'nullable', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // Per-row rules shared with the store request. `sometimes` on the
            // array itself is the one difference: an ABSENT key leaves the
            // existing rows untouched, while a present one REPLACES the whole
            // set — so [] clears the table.
            ...[...StoreChangeOrderRequest::scopeItemRules(), 'scope_items' => ['sometimes', 'nullable', 'array']],
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
