<?php

namespace App\Http\Requests\Api\V1\TradeCategory;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreTradeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('trade_categories', 'name')
                    ->where(fn ($q) => $q->where('parent_id', $this->input('parent_id')))
                    ->whereNull('deleted_at'),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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
