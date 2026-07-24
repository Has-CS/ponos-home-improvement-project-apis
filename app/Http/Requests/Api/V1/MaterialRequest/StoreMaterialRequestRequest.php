<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'urgency_id' => ['required', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'needed_by_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            // Optional convenience: create the request with its lines in one call.
            'items' => ['nullable', 'array'],
            ...StoreMaterialRequestItemRequest::lineRules('items.*.'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('items', []) as $i => $item) {
                if (empty($item['catalog_item_id']) && empty($item['description'])) {
                    $v->errors()->add("items.{$i}.description", 'A line must have either a catalog item or a description.');
                }
            }
        });
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
