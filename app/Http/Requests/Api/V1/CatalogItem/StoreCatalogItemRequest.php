<?php

namespace App\Http\Requests\Api\V1\CatalogItem;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trade_category_id' => ['required', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'catalog_item_type_id' => ['required', 'integer', Rule::exists('catalog_item_types', 'id')->whereNull('deleted_at')],
            'default_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'sku' => [
                'nullable', 'string', 'max:60',
                Rule::unique('catalog_items', 'sku')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'is_custom' => ['nullable', 'boolean'],
            'attributes' => ['nullable', 'array'],
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
