<?php

namespace App\Http\Requests\Api\V1\CatalogItem;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as the literal text "true"/"false",
        // which Laravel's `boolean` rule does NOT accept (only 1/0/"1"/"0"/
        // true/false) — normalize here so ?is_custom=false validates.
        if ($this->has('is_custom')) {
            $this->merge(['is_custom' => filter_var($this->query('is_custom'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'trade_category_id' => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'catalog_item_type_id' => ['nullable', 'integer', Rule::exists('catalog_item_types', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'is_custom' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['name', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
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
