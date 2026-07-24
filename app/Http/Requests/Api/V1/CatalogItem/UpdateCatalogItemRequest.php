<?php

namespace App\Http\Requests\Api\V1\CatalogItem;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $catalogItem = $this->route('catalog_item');

        return [
            'trade_category_id' => ['sometimes', 'required', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'catalog_item_type_id' => ['sometimes', 'required', 'integer', Rule::exists('catalog_item_types', 'id')->whereNull('deleted_at')],
            'default_unit_id' => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'sku' => [
                'sometimes', 'nullable', 'string', 'max:60',
                Rule::unique('catalog_items', 'sku')->whereNull('deleted_at')->ignore($catalogItem->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_custom' => ['sometimes', 'boolean'],
            'attributes' => ['sometimes', 'nullable', 'array'],
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
